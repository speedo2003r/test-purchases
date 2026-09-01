<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPurchaseWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_purchase_index_page_and_renders_expected_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $service = Service::factory()->create([
            'name' => 'Web Design Bootcamp',
            'price' => 450.00,
        ]);
        $user = User::factory()->create([
            'name' => 'Alice Purchaser',
            'email' => 'alice@example.com',
        ]);

        $purchase = Purchase::factory()->create([
            'service_id' => $service->id,
            'user_id' => $user->id,
            'status' => Purchase::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($admin)->get('/admin/purchases');

        $response->assertOk();
        $response->assertViewIs('admin.purchases.index');
        $response->assertSeeText('#'.$purchase->id);
        $response->assertSeeText('Alice Purchaser');
        $response->assertSeeText('alice@example.com');
        $response->assertSeeText('Web Design Bootcamp');
        $response->assertSeeText('$450.00');
        $response->assertSeeText('confirmed');
        $response->assertSeeText($purchase->created_at->format('Y-m-d H:i:s'));
        $response->assertSeeText($purchase->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_non_admin_cannot_access_admin_web_endpoints(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $purchase = Purchase::factory()->create();

        $this->actingAs($regularUser)->get('/admin/purchases')->assertForbidden();
        $this->actingAs($regularUser)->get("/admin/purchases/{$purchase->id}")->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/admin/purchases');
        $response->assertRedirect('/login');
    }

    public function test_admin_can_filter_purchases_by_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $service = Service::factory()->create();

        $confirmed = Purchase::factory()->create([
            'service_id' => $service->id,
            'status' => Purchase::STATUS_CONFIRMED,
        ]);
        $failed = Purchase::factory()->create([
            'service_id' => $service->id,
            'status' => Purchase::STATUS_FAILED,
        ]);

        $response = $this->actingAs($admin)->get('/admin/purchases?status=confirmed');

        $response->assertOk();
        $purchases = $response->viewData('purchases');
        $this->assertTrue($purchases->contains('id', $confirmed->id));
        $this->assertFalse($purchases->contains('id', $failed->id));
        $response->assertSeeText('#'.$confirmed->id);
        $response->assertSee('purchase-row-'.$confirmed->id);
        $response->assertDontSee('purchase-row-'.$failed->id);
    }

    public function test_admin_can_filter_purchases_by_service(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $serviceA = Service::factory()->create(['name' => 'Service Alpha Unique']);
        $serviceB = Service::factory()->create(['name' => 'Service Beta Unique']);

        $purchaseA = Purchase::factory()->create(['service_id' => $serviceA->id]);
        $purchaseB = Purchase::factory()->create(['service_id' => $serviceB->id]);

        $response = $this->actingAs($admin)->get("/admin/purchases?service_id={$serviceA->id}");

        $response->assertOk();
        $purchases = $response->viewData('purchases');
        $this->assertTrue($purchases->contains('id', $purchaseA->id));
        $this->assertFalse($purchases->contains('id', $purchaseB->id));
        $response->assertSee('purchase-row-'.$purchaseA->id);
        $response->assertDontSee('purchase-row-'.$purchaseB->id);
    }

    public function test_admin_can_filter_purchases_by_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $userA = User::factory()->create(['name' => 'Charlie First', 'email' => 'charlie@first.com']);
        $userB = User::factory()->create(['name' => 'David Second', 'email' => 'david@second.com']);

        $purchaseA = Purchase::factory()->create(['user_id' => $userA->id]);
        $purchaseB = Purchase::factory()->create(['user_id' => $userB->id]);

        $response = $this->actingAs($admin)->get("/admin/purchases?user={$userA->id}");

        $response->assertOk();
        $purchases = $response->viewData('purchases');
        $this->assertTrue($purchases->contains('id', $purchaseA->id));
        $this->assertFalse($purchases->contains('id', $purchaseB->id));
        $response->assertSee('purchase-row-'.$purchaseA->id);
        $response->assertDontSee('purchase-row-'.$purchaseB->id);

        $responseByName = $this->actingAs($admin)->get('/admin/purchases?user=Charlie');
        $responseByName->assertOk();
        $this->assertTrue($responseByName->viewData('purchases')->contains('id', $purchaseA->id));
        $this->assertFalse($responseByName->viewData('purchases')->contains('id', $purchaseB->id));
        $responseByName->assertSee('purchase-row-'.$purchaseA->id);
        $responseByName->assertDontSee('purchase-row-'.$purchaseB->id);
    }

    public function test_admin_can_view_purchase_detail_with_attempts_and_events(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $service = Service::factory()->create([
            'name' => 'Masterclass Photography',
            'price' => 299.00,
        ]);
        $user = User::factory()->create([
            'name' => 'Bob Buyer',
            'email' => 'bob@buyer.com',
        ]);

        $purchase = Purchase::factory()->create([
            'service_id' => $service->id,
            'user_id' => $user->id,
            'status' => Purchase::STATUS_CONFIRMED,
            'request_key' => 'req-unique-12345',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'purchase_id' => $purchase->id,
            'attempt_no' => 1,
            'provider_reference' => 'pay_ref_abcdef123',
            'status' => PaymentAttempt::STATUS_SUCCEEDED,
        ]);

        $purchase->update(['current_attempt_id' => $attempt->id]);

        $event = PaymentEvent::factory()->create([
            'payment_attempt_id' => $attempt->id,
            'provider_event_id' => 'evt_xyz987654',
            'event_type' => PaymentEvent::TYPE_SUCCESS,
            'occurred_at' => now()->subMinute(),
            'processed_at' => now(),
            'raw_payload' => ['sample_key' => 'sample_value_123'],
        ]);

        $response = $this->actingAs($admin)->get("/admin/purchases/{$purchase->id}");

        $response->assertOk();
        $response->assertViewIs('admin.purchases.show');
        $response->assertSeeText('Purchase #'.$purchase->id);
        $response->assertSeeText('req-unique-12345');
        $response->assertSeeText('Bob Buyer');
        $response->assertSeeText('bob@buyer.com');
        $response->assertSeeText('Masterclass Photography');
        $response->assertSeeText('$299.00');
        $response->assertSeeText('pay_ref_abcdef123');
        $response->assertSeeText('evt_xyz987654');
        $response->assertSeeText('sample_value_123');
    }

    public function test_admin_can_login_via_web_form_and_logout(): void
    {
        $admin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => bcrypt('secret-password-123'),
            'is_admin' => true,
        ]);

        $loginResponse = $this->post('/login', [
            'email' => 'superadmin@example.com',
            'password' => 'secret-password-123',
        ]);

        $loginResponse->assertRedirect('/admin/purchases');
        $this->assertAuthenticatedAs($admin);

        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/login');
        $this->assertGuest();
    }
}
