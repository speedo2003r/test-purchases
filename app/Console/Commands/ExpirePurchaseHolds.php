<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:expire-purchase-holds')]
#[Description('Cancel pending purchases whose hold has expired, releasing their spot')]
class ExpirePurchaseHolds extends Command
{
    /**
     * Execute the console command.
     *
     * Only cancels rows — never creates or confirms them — so this is safe
     * to run without the service-row lock: it can only ever free capacity,
     * never oversell it. CreatePurchaseAction performs the same sweep under
     * lock as a correctness backstop if this hasn't run recently enough.
     */
    public function handle(): int
    {
        $count = Purchase::query()
            ->where('status', Purchase::STATUS_PENDING)
            ->where('hold_expires_at', '<', now())
            ->update(['status' => Purchase::STATUS_CANCELLED]);

        $this->info("Expired {$count} abandoned purchase hold(s).");

        return self::SUCCESS;
    }
}
