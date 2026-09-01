<?php

namespace Tests\Feature;

use App\Actions\StartPaymentAttemptAction;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPurchaseWithAttempt(): array
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id'    => User::factory()->create()->id,
        ]);
        $attempt = (new StartPaymentAttemptAction())->execute($purchase);

        return [$purchase, $attempt];
    }

    /**
     * Post a signed webhook request.
     *
     * Computes the HMAC-SHA256 of the JSON-encoded payload using the test
     * secret defined in phpunit.xml and sends it in X-Payment-Signature.
     */
    private function signedWebhookPost(array $payload, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        $secret ??= config('services.payment_provider.webhook_secret');
        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $secret);

        return $this->postJson(
            '/api/webhooks/payments',
            $payload,
            ['X-Payment-Signature' => $signature],
        );
    }

    // -------------------------------------------------------------------------
    // Existing functional tests — updated to include valid signature
    // -------------------------------------------------------------------------

    public function test_webhook_confirms_purchase_on_success(): void
    {
        [$purchase, $attempt] = $this->pendingPurchaseWithAttempt();

        $response = $this->signedWebhookPost([
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertSame(Purchase::STATUS_CONFIRMED, $purchase->fresh()->status);
    }

    public function test_duplicate_webhook_delivery_is_a_no_op(): void
    {
        [, $attempt] = $this->pendingPurchaseWithAttempt();
        $payload = [
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ];

        $this->signedWebhookPost($payload)->assertOk();
        $this->signedWebhookPost($payload)->assertOk();

        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_webhook_for_unknown_reference_is_acknowledged_but_ignored(): void
    {
        $response = $this->signedWebhookPost([
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => 'does-not-exist',
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_webhook_rejects_invalid_event_type(): void
    {
        [, $attempt] = $this->pendingPurchaseWithAttempt();

        $this->signedWebhookPost([
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => 'bogus',
            'occurred_at'        => now()->toIso8601String(),
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // D3 — Webhook authentication tests
    // -------------------------------------------------------------------------

    /**
     * A correctly signed request is processed normally.
     *
     * Covers requirement: valid authentication is accepted.
     */
    public function test_webhook_with_valid_signature_is_accepted(): void
    {
        [, $attempt] = $this->pendingPurchaseWithAttempt();

        $this->signedWebhookPost([
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('status', 'ok');
    }

    /**
     * A request without any X-Payment-Signature header is rejected with 401.
     *
     * Covers requirement: missing authentication is rejected.
     */
    public function test_webhook_with_missing_signature_is_rejected(): void
    {
        [, $attempt] = $this->pendingPurchaseWithAttempt();

        // Post without the signature header.
        $this->postJson('/api/webhooks/payments', [
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ])->assertStatus(401);

        // No event must have been created.
        $this->assertDatabaseCount('payment_events', 0);
    }

    /**
     * A request carrying an incorrect signature is rejected with 401.
     *
     * Covers requirement: invalid authentication is rejected.
     */
    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        [, $attempt] = $this->pendingPurchaseWithAttempt();

        $payload = [
            'provider_event_id'  => (string) Str::uuid(),
            'provider_reference' => $attempt->provider_reference,
            'event_type'         => PaymentEvent::TYPE_SUCCESS,
            'occurred_at'        => now()->toIso8601String(),
        ];

        // Sign with a wrong secret.
        $this->signedWebhookPost($payload, 'wrong-secret')
            ->assertStatus(401);

        $this->assertDatabaseCount('payment_events', 0);
    }
}