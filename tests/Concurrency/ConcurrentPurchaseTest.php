<?php

namespace Tests\Concurrency;

use App\Actions\CreatePurchaseAction;
use App\Actions\ProcessPaymentEventAction;
use App\Actions\StartPaymentAttemptAction;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves concurrency invariants under real concurrent writers using forked
 * OS processes (pcntl_fork), not a sequential loop.
 *
 * A PHP process holds one MySQL connection for its whole lifetime, so a
 * sequential foreach in one process would only ever have one transaction
 * open at a time — it can never exercise FOR UPDATE lock-wait contention
 * or unique-constraint races that concurrent writers produce.
 *
 * Each test forks real child OS processes, each with its own fresh DB
 * connection. All children are held at a start barrier (a shared pipe read)
 * until every child is spawned and ready, then released simultaneously —
 * producing genuine concurrent transactions against the same MySQL rows.
 */
class ConcurrentPurchaseTest extends TestCase
{
    use DatabaseTruncation;

    // ---------------------------------------------------------------------------
    // Test 1: Oversell prevention
    // ---------------------------------------------------------------------------

    public function test_fifty_concurrent_buyers_never_oversell_ten_spots(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $totalSpots = 10;
        $concurrentBuyers = 50;

        $service = Service::factory()->create(['total_spots' => $totalSpots]);
        $users = User::factory()->count($concurrentBuyers)->create();

        // Close the parent's DB connection before forking so children don't
        // inherit and fight over the same underlying socket.
        DB::disconnect();

        [$readEnd, $writeEnd] = $this->makePipe();

        $childPids = [];
        foreach ($users as $user) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Failed to fork child process.');
            }

            if ($pid === 0) {
                // Child: block on the pipe until the parent closes the
                // write end, releasing every child at once.
                fclose($writeEnd);
                fread($readEnd, 1);
                fclose($readEnd);

                $this->attemptPurchaseInChildProcess($user->id, $service->id);
                exit(0);
            }

            $childPids[] = $pid;
        }

        fclose($readEnd);
        // Releasing the barrier: closing the write end sends EOF to every
        // blocked fread() in the children simultaneously.
        fclose($writeEnd);

        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $reservedCount = Purchase::query()
            ->where('service_id', $service->id)
            ->whereIn('status', Purchase::OPEN_STATUSES)
            ->count();

        $totalPurchaseRows = Purchase::query()->where('service_id', $service->id)->count();

        $this->assertSame(
            $totalSpots,
            $reservedCount,
            'Exactly total_spots purchases should hold a reservation — the oversell invariant.',
        );
        $this->assertSame(
            $totalSpots,
            $totalPurchaseRows,
            'Losing buyers must not leave any purchase row behind — they should fail before insert.',
        );
    }

    // ---------------------------------------------------------------------------
    // Test 2: Concurrent duplicate payment events — idempotency under real concurrency
    // ---------------------------------------------------------------------------

    /**
     * Proves that delivering the EXACT same provider_event_id from N concurrent
     * processes applies the payment effect exactly once.
     *
     * Each child calls ProcessPaymentEventAction::execute() with the same
     * $providerEventId and TYPE_SUCCESS simultaneously. The unique index on
     * payment_events.provider_event_id ensures only one insert succeeds; the
     * catch-and-re-fetch path in recordEventOrGetExisting() makes all losers
     * read the already-inserted row and short-circuit on processed_at != null.
     *
     * This test would fail (multiple payment_events rows, or a duplicate
     * confirmed status effect) if the unique index on provider_event_id were
     * removed.
     */
    public function test_concurrent_duplicate_payment_events_are_idempotent(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $concurrentDeliveries = 20;

        // Set up purchase + attempt in the parent before forking.
        $service  = Service::factory()->create(['total_spots' => 5]);
        $user     = User::factory()->create();
        $purchase = Purchase::factory()->create([
            'service_id' => $service->id,
            'user_id'    => $user->id,
        ]);
        $attempt = (new StartPaymentAttemptAction())->execute($purchase);

        // All children will send exactly this event id.
        $providerEventId = (string) Str::uuid();

        DB::disconnect();

        [$readEnd, $writeEnd] = $this->makePipe();

        $childPids = [];
        for ($i = 0; $i < $concurrentDeliveries; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Failed to fork child process.');
            }

            if ($pid === 0) {
                fclose($writeEnd);
                fread($readEnd, 1);
                fclose($readEnd);

                $this->deliverDuplicateEventInChildProcess(
                    $attempt->id,
                    $providerEventId,
                );
                exit(0);
            }

            $childPids[] = $pid;
        }

        fclose($readEnd);
        fclose($writeEnd); // releases all children simultaneously

        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $eventCount = PaymentEvent::query()
            ->where('provider_event_id', $providerEventId)
            ->count();

        $finalStatus = Purchase::query()
            ->whereKey($purchase->id)
            ->value('status');

        $this->assertSame(
            1,
            $eventCount,
            'Exactly one payment_events row must exist for a single provider_event_id, '
            . 'regardless of how many concurrent processes attempted to insert it.',
        );

        $this->assertSame(
            Purchase::STATUS_CONFIRMED,
            $finalStatus,
            'Purchase must be confirmed exactly once — not double-confirmed or left pending.',
        );
    }

    // ---------------------------------------------------------------------------
    // Test 3: Concurrent duplicate purchase requests — one purchase per request_key
    // ---------------------------------------------------------------------------

    /**
     * Proves that N concurrent processes submitting the SAME request_key produce
     * exactly ONE purchase row, consuming exactly ONE spot.
     *
     * The unique index on purchases.request_key means only one INSERT can win.
     * CreatePurchaseAction catches the resulting QueryException (SQLSTATE 23000)
     * and returns the already-inserted row to all losers, so every caller
     * resolves to the same purchase.
     *
     * This test would fail (multiple purchase rows, multiple spots consumed) if
     * the unique index on request_key were removed.
     */
    public function test_concurrent_duplicate_purchase_requests_create_exactly_one_purchase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $concurrentRequests = 20;
        $totalSpots = 5;

        $service    = Service::factory()->create(['total_spots' => $totalSpots]);
        $user       = User::factory()->create();
        $requestKey = (string) Str::uuid(); // shared by all children

        DB::disconnect();

        [$readEnd, $writeEnd] = $this->makePipe();

        $childPids = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Failed to fork child process.');
            }

            if ($pid === 0) {
                fclose($writeEnd);
                fread($readEnd, 1);
                fclose($readEnd);

                $this->attemptDuplicatePurchaseInChildProcess(
                    $user->id,
                    $service->id,
                    $requestKey,
                );
                exit(0);
            }

            $childPids[] = $pid;
        }

        fclose($readEnd);
        fclose($writeEnd);

        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $purchaseCount = Purchase::query()
            ->where('request_key', $requestKey)
            ->count();

        $openCount = Purchase::query()
            ->where('service_id', $service->id)
            ->whereIn('status', Purchase::OPEN_STATUSES)
            ->count();

        $this->assertSame(
            1,
            $purchaseCount,
            'Exactly one purchase must exist for a given request_key, even when '
            . $concurrentRequests . ' processes submit it simultaneously.',
        );

        $this->assertSame(
            1,
            $openCount,
            'Exactly one spot must be consumed — duplicate requests must not '
            . 'create duplicate capacity reservations.',
        );
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @return array{0: resource, 1: resource}
     */
    private function makePipe(): array
    {
        $streams = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );

        if ($streams === false) {
            $this->fail('Failed to create synchronization socket pair.');
        }

        return $streams;
    }

    private function attemptPurchaseInChildProcess(int $userId, int $serviceId): void
    {
        // Fresh connection for this process — Laravel's DB manager was
        // disconnected before forking, so this reconnects independently.
        $user    = User::query()->find($userId);
        $service = Service::query()->find($serviceId);

        try {
            (new CreatePurchaseAction())->execute($user, $service, (string) Str::uuid());
        } catch (\Throwable $e) {
            // Expected for buyers who lose the race — swallow so the
            // process exits cleanly; the parent verifies outcomes by
            // counting rows, not by child exit codes.
        }
    }

    private function deliverDuplicateEventInChildProcess(
        int $attemptId,
        string $providerEventId,
    ): void {
        $attempt = \App\Models\PaymentAttempt::query()->find($attemptId);

        try {
            (new ProcessPaymentEventAction())->execute(
                $attempt,
                $providerEventId,
                PaymentEvent::TYPE_SUCCESS,
                new \DateTimeImmutable(),
                ['simulated' => true],
            );
        } catch (\Throwable $e) {
            // Losers that couldn't acquire the lock or hit a transient error
            // exit cleanly; the parent checks the final DB state.
        }
    }

    private function attemptDuplicatePurchaseInChildProcess(
        int $userId,
        int $serviceId,
        string $requestKey,
    ): void {
        $user    = User::query()->find($userId);
        $service = Service::query()->find($serviceId);

        try {
            (new CreatePurchaseAction())->execute($user, $service, $requestKey);
        } catch (\Throwable $e) {
            // If the service row lock causes a NoAvailabilityException on
            // a loser that already sees one purchase holding the spot, that
            // is acceptable — the parent only checks that exactly one row
            // was created, not that every child succeeded.
        }
    }
}