<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProcessPaymentEventAction;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessPaymentEventAction $action): JsonResponse
    {
        $this->verifySignature($request);

        $data = $request->validate([
            'provider_event_id' => ['required', 'string'],
            'provider_reference' => ['required', 'string'],
            'event_type' => ['required', 'in:success,failed,cancelled'],
            'occurred_at' => ['required', 'date'],
        ]);

        $attempt = PaymentAttempt::query()
            ->where('provider_reference', $data['provider_reference'])
            ->first();

        if ($attempt === null) {
            // Unknown reference: nothing we can do by retrying. Ack so the
            // provider doesn't retry-storm us, but do not process.
            return response()->json(['status' => 'ignored'], 200);
        }

        $event = $action->execute(
            $attempt,
            $data['provider_event_id'],
            $data['event_type'],
            new \DateTimeImmutable($data['occurred_at']),
            $request->all(),
        );

        return response()->json(['status' => 'ok', 'event_id' => $event->id], 200);
    }

    /**
     * Verifies the HMAC-SHA256 signature sent by the payment provider.
     *
     * The provider must include an X-Payment-Signature header containing the
     * lowercase hex-encoded HMAC-SHA256 of the raw request body, keyed with
     * the shared secret stored in PAYMENT_WEBHOOK_SECRET. Requests that are
     * missing the header or carry an invalid signature are rejected with 401.
     *
     * Using hash_equals() prevents timing-based signature oracle attacks.
     * The secret is never logged or included in any response.
     */
    private function verifySignature(Request $request): void
    {
        $secret = config('services.payment_provider.webhook_secret');

        $signature = $request->header('X-Payment-Signature', '');

        $expected = hash_hmac('sha256', $request->getContent(), (string) $secret);

        abort_if(
            ! $secret || ! hash_equals($expected, $signature),
            401,
            'Invalid or missing webhook signature.',
        );
    }
}