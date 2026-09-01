<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_id' => Service::factory(),
            'status' => Purchase::STATUS_PENDING,
            'request_key' => (string) Str::uuid(),
            'hold_expires_at' => now()->addMinutes(15),
        ];
    }
}
