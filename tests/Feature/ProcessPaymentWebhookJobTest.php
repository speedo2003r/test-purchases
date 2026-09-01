<?php

namespace Tests\Feature;

use App\Actions\StartPaymentAttemptAction;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessPaymentWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_event_and_confirms_purchase(): void
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $attempt = (new StartPaymentAttemptAction())->execute($purchase);

        $job = new ProcessPaymentWebhookJob(
            $attempt->id,
            (string) Str::uuid(),
            PaymentEvent::TYPE_SUCCESS,
            now()->toIso8601String(),
        );
        $job->handle(app(\App\Actions\ProcessPaymentEventAction::class));

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
    }

    /**
     * Simulates a temporary failure (e.g. a transient DB error) during
     * processing, followed by the queue's automatic retry. Because the
     * event id is fixed across both attempts and the failed run rolled its
     * transaction back entirely (no partial commit), the retry must reach
     * the same correct final state as if it had succeeded on the first try.
     */
    public function test_retry_after_transient_failure_reaches_correct_state_without_double_effect(): void
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $attempt = (new StartPaymentAttemptAction())->execute($purchase);
        $eventId = (string) Str::uuid();

        $failingAction = new class extends \App\Actions\ProcessPaymentEventAction
        {
            public static int $callCount = 0;

            public function execute(
                \App\Models\PaymentAttempt $attempt,
                string $providerEventId,
                string $eventType,
                \DateTimeInterface $occurredAt,
                array $rawPayload = [],
            ): PaymentEvent {
                self::$callCount++;
                if (self::$callCount === 1) {
                    throw new \RuntimeException('Simulated transient failure (e.g. DB connection blip).');
                }

                return parent::execute($attempt, $providerEventId, $eventType, $occurredAt, $rawPayload);
            }
        };

        $job = new ProcessPaymentWebhookJob($attempt->id, $eventId, PaymentEvent::TYPE_SUCCESS, now()->toIso8601String());

        try {
            $job->handle($failingAction);
            $this->fail('Expected the first attempt to throw.');
        } catch (\RuntimeException) {
            // expected: simulates the queue worker catching it and retrying
        }

        $this->assertSame(Purchase::STATUS_PENDING, $purchase->fresh()->status);
        $this->assertDatabaseCount('payment_events', 0);

        // Queue retry: same job payload, same event id.
        $job->handle($failingAction);

        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
        $this->assertDatabaseCount('payment_events', 1);
    }
}
