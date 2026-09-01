<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePurchaseHoldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_cancels_expired_pending_purchases_and_frees_the_spot(): void
    {
        $service = Service::factory()->create(['total_spots' => 1]);
        $abandoned = Purchase::factory()->create([
            'service_id' => $service->id,
            'user_id' => User::factory()->create()->id,
            'status' => Purchase::STATUS_PENDING,
            'hold_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('app:expire-purchase-holds')->assertSuccessful();

        $this->assertSame(Purchase::STATUS_CANCELLED, $abandoned->fresh()->status);
    }

    public function test_command_does_not_touch_purchases_still_within_their_hold(): void
    {
        $purchase = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'status' => Purchase::STATUS_PENDING,
            'hold_expires_at' => now()->addMinutes(10),
        ]);

        $this->artisan('app:expire-purchase-holds')->assertSuccessful();

        $this->assertSame(Purchase::STATUS_PENDING, $purchase->fresh()->status);
    }

    public function test_command_does_not_touch_terminal_statuses(): void
    {
        $confirmed = Purchase::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'status' => Purchase::STATUS_CONFIRMED,
            'hold_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('app:expire-purchase-holds')->assertSuccessful();

        $this->assertSame(Purchase::STATUS_CONFIRMED, $confirmed->fresh()->status);
    }
}
