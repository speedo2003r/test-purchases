<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application database with demo data for manual evaluation.
     *
     * Safe to re-run: firstOrCreate prevents duplicates.
     * Existing Sanctum tokens are replaced so the output is always fresh.
     *
     * Creates:
     *   - One admin user  (admin@example.com / password, is_admin=true)
     *   - One regular user (user@example.com / password)
     *   - One sample Service: "Weekend Photography Workshop"
     *     $299.00, 10 spots, available now through +30 days
     *
     * Prints Sanctum tokens for both users so a fresh evaluator can
     * immediately call the API without any additional setup.
     */
    public function run(): void
    {
        // Admin user -------------------------------------------------------
        /** @var User $admin */
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin User',
                'password' => bcrypt('password'),
                'is_admin' => true,
            ],
        );

        // Regular user -----------------------------------------------------
        /** @var User $user */
        $user = User::firstOrCreate(
            ['email' => 'user@example.com'],
            [
                'name'     => 'Demo User',
                'password' => bcrypt('password'),
                'is_admin' => false,
            ],
        );

        // Sample service ---------------------------------------------------
        /** @var Service $service */
        $service = Service::firstOrCreate(
            ['name' => 'Weekend Photography Workshop'],
            [
                'price'           => '299.00',
                'total_spots'     => 10,
                'available_from'  => now(),
                'available_until' => now()->addDays(30),
            ],
        );

        // Sanctum tokens ---------------------------------------------------
        // Replace any existing tokens so re-running always prints fresh ones.
        $admin->tokens()->delete();
        $user->tokens()->delete();

        $adminToken = $admin->createToken('seeder-admin')->plainTextToken;
        $userToken  = $user->createToken('seeder-user')->plainTextToken;

        // Output -----------------------------------------------------------
        $serviceName  = $service->name;
        $servicePrice = $service->price;
        $serviceSpots = $service->total_spots;
        $serviceId    = $service->id;

        $this->command->newLine();
        $this->command->info('=== Demo Seed Complete ===');
        $this->command->newLine();
        $this->command->line('Credentials:');
        $this->command->line('  Admin : admin@example.com / password  (is_admin=true)');
        $this->command->line('  User  : user@example.com  / password');
        $this->command->newLine();
        $this->command->line("Service : \"{$serviceName}\" — \${$servicePrice} — {$serviceSpots} spots");
        $this->command->line("Service ID : {$serviceId}");
        $this->command->newLine();
        $this->command->warn('Bearer tokens for API calls:');
        $this->command->line("  ADMIN_TOKEN={$adminToken}");
        $this->command->line("  USER_TOKEN={$userToken}");
        $this->command->newLine();
    }
}