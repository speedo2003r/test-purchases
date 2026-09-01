<?php

namespace Tests\Feature;

use App\Actions\ProcessPaymentEventAction;
use App\Actions\StartPaymentAttemptAction;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessPaymentEventActionTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPurchaseWithAttempt(): array
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $attempt = (new StartPaymentAttemptAction())->execute($purchase);

        return [$purchase->fresh(), $attempt];
    }

    public function test_success_event_confirms_purchase(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();

        (new ProcessPaymentEventAction())->execute(
            $attempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, now(),
        );

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
    }

    public function test_same_success_event_received_multiple_times_confirms_exactly_once(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();
        $eventId = (string) Str::uuid();
        $action = new ProcessPaymentEventAction();

        $action->execute($attempt, $eventId, PaymentEvent::TYPE_SUCCESS, now());
        $action->execute($attempt, $eventId, PaymentEvent::TYPE_SUCCESS, now());
        $action->execute($attempt, $eventId, PaymentEvent::TYPE_SUCCESS, now());

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_delayed_payment_result_still_confirms_correctly(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();

        // Simulate a result that took a long time to arrive, well past a
        // "normal" expectation but still inside the hold window.
        $lateOccurredAt = now()->subMinutes(5);

        (new ProcessPaymentEventAction())->execute(
            $attempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, $lateOccurredAt,
        );

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
    }

    public function test_out_of_order_failed_after_success_does_not_overwrite_confirmed(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();
        $action = new ProcessPaymentEventAction();

        $action->execute($attempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, now());
        // A failed notification for the same attempt arrives after success —
        // must not flip a confirmed purchase back to failed.
        $action->execute($attempt, (string) Str::uuid(), PaymentEvent::TYPE_FAILED, now()->subSecond());

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
    }

    public function test_stale_attempt_event_after_retry_is_ignored(): void
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $startAction = new StartPaymentAttemptAction();
        $processAction = new ProcessPaymentEventAction();

        $firstAttempt = $startAction->execute($purchase);
        $processAction->execute($firstAttempt, (string) Str::uuid(), PaymentEvent::TYPE_FAILED, now());

        // User retries payment -> new attempt becomes current.
        $secondAttempt = $startAction->execute($purchase->fresh());
        $processAction->execute($secondAttempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, now());

        // A late success for the first (now-stale) attempt arrives.
        $processAction->execute($firstAttempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, now());

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
        $this->assertSame($secondAttempt->id, $purchase->fresh()->current_attempt_id);
    }

    public function test_success_arriving_after_hold_expired_does_not_confirm(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();
        $purchase->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        (new ProcessPaymentEventAction())->execute(
            $attempt, (string) Str::uuid(), PaymentEvent::TYPE_SUCCESS, now(),
        );

        $this->assertSame(Purchase::STATUS_CANCELLED, $purchase->fresh()->status);
    }
}
