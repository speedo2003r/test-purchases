<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_purchase(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['total_spots' => 5]);

        $response = $this->actingAs($user)->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', Purchase::STATUS_PENDING);
    }

    public function test_repeated_submission_with_same_request_key_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['total_spots' => 5]);
        $requestKey = (string) Str::uuid();

        $first = $this->actingAs($user)->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => $requestKey,
        ]);
        $second = $this->actingAs($user)->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => $requestKey,
        ]);

        $first->assertCreated();
        $second->assertOk(); // idempotent replay, not a second creation
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_returns_409_when_service_is_sold_out(): void
    {
        $service = Service::factory()->create(['total_spots' => 1]);
        $this->actingAs(User::factory()->create())->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => (string) Str::uuid(),
        ])->assertCreated();

        $response = $this->actingAs(User::factory()->create())->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'no_availability');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $service = Service::factory()->create();

        $this->postJson("/api/services/{$service->id}/purchases", [
            'request_key' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    public function test_user_cannot_view_another_users_purchase(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $purchase = Purchase::factory()->create([
            'user_id' => $owner->id,
            'service_id' => Service::factory()->create()->id,
        ]);

        $this->actingAs($stranger)->getJson("/api/purchases/{$purchase->id}")->assertForbidden();
    }
}
