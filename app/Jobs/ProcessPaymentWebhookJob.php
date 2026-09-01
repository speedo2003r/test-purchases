<?php

namespace App\Jobs;

use App\Actions\ProcessPaymentEventAction;
use App\Models\PaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Represents a payment-provider webhook delivery. Dispatched with a delay by
 * the fake provider to simulate "payment result arrives later", and safe to
 * retry (Laravel's default queue retry/backoff) because the underlying
 * action is idempotent on provider_event_id.
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(
        public readonly int $paymentAttemptId,
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly string $occurredAt,
        public readonly array $rawPayload = [],
    ) {
    }

    public function handle(ProcessPaymentEventAction $action): void
    {
        $attempt = PaymentAttempt::query()->findOrFail($this->paymentAttemptId);

        $action->execute(
            $attempt,
            $this->providerEventId,
            $this->eventType,
            new \DateTimeImmutable($this->occurredAt),
            $this->rawPayload,
        );
    }
}
