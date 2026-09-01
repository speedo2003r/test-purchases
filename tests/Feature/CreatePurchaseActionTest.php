<?php

namespace Tests\Feature;

use App\Actions\CreatePurchaseAction;
use App\Exceptions\NoAvailabilityException;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatePurchaseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_purchase_and_reserves_a_spot(): void
    {
        $service = Service::factory()->create(['total_spots' => 5]);
        $user = User::factory()->create();

        $purchase = (new CreatePurchaseAction())->execute($user, $service, (string) Str::uuid());

        $this->assertSame(Purchase::STATUS_PENDING, $purchase->status);
        $this->assertNotNull($purchase->hold_expires_at);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_rejects_purchase_when_no_spots_remain(): void
    {
        $service = Service::factory()->create(['total_spots' => 1]);
        $action = new CreatePurchaseAction();

        $action->execute(User::factory()->create(), $service, (string) Str::uuid());

        $this->expectException(NoAvailabilityException::class);
        $action->execute(User::factory()->create(), $service, (string) Str::uuid());
    }

    public function test_repeated_request_key_returns_same_purchase_instead_of_creating_duplicate(): void
    {
        $service = Service::factory()->create(['total_spots' => 5]);
        $user = User::factory()->create();
        $requestKey = (string) Str::uuid();
        $action = new CreatePurchaseAction();

        $first = $action->execute($user, $service, $requestKey);
        $second = $action->execute($user, $service, $requestKey);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_expired_hold_frees_the_spot_for_the_next_purchaser(): void
    {
        $service = Service::factory()->create(['total_spots' => 1]);
        $action = new CreatePurchaseAction();

        $first = $action->execute(User::factory()->create(), $service, (string) Str::uuid());
        $first->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        $second = $action->execute(User::factory()->create(), $service, (string) Str::uuid());

        $this->assertSame(Purchase::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(Purchase::STATUS_PENDING, $second->status);
    }

    public function test_rejects_purchase_outside_availability_window(): void
    {
        $service = Service::factory()->create([
            'available_from' => now()->subWeek(),
            'available_until' => now()->subDay(),
        ]);

        $this->expectException(\App\Exceptions\ServiceNotAvailableException::class);
        (new CreatePurchaseAction())->execute(User::factory()->create(), $service, (string) Str::uuid());
    }
}
