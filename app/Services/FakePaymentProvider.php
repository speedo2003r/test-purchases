<?php

namespace App\Services;

use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use Illuminate\Support\Str;

/**
 * Stands in for a real external payment gateway. In production this would
 * be replaced by an HTTP client hitting the real provider, with the webhook
 * route (see routes/api.php) receiving its async result. Here, "sending"
 * a charge simply schedules the same-shaped result to be delivered to our
 * own idempotent processing pipeline after a delay — exercising exactly the
 * same code path a real webhook would.
 */
class FakePaymentProvider
{
    public function charge(PaymentAttempt $attempt, string $outcome = PaymentEvent::TYPE_SUCCESS, int $delaySeconds = 3): void
    {
        ProcessPaymentWebhookJob::dispatch(
            $attempt->id,
            (string) Str::uuid(),
            $outcome,
            now()->toIso8601String(),
            ['provider_reference' => $attempt->provider_reference, 'simulated' => true],
        )->delay(now()->addSeconds($delaySeconds));
    }
}
