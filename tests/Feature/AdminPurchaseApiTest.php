<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_purchases_with_expected_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'status' => Purchase::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/purchases');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $purchase->id);
        $response->assertJsonPath('data.0.status', Purchase::STATUS_CONFIRMED);
        $response->assertJsonStructure([
            'data' => [['id', 'status', 'user', 'service', 'created_at']],
        ]);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $service = Service::factory()->create();
        Purchase::factory()->create(['service_id' => $service->id, 'user_id' => User::factory()->create()->id, 'status' => Purchase::STATUS_CONFIRMED]);
        Purchase::factory()->create(['service_id' => $service->id, 'user_id' => User::factory()->create()->id, 'status' => Purchase::STATUS_FAILED]);

        $response = $this->actingAs($admin)->getJson('/api/admin/purchases?status=failed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', Purchase::STATUS_FAILED);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/api/admin/purchases')->assertForbidden();
    }
}
