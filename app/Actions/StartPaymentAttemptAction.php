<?php

namespace App\Actions;

use App\Exceptions\NoAvailabilityException;
use App\Exceptions\PurchaseNotPayableException;
use App\Models\PaymentAttempt;
use App\Models\Purchase;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartPaymentAttemptAction
{
    public function __construct(
        private readonly int $holdMinutes = 15,
    ) {
    }

    /**
     * Starts (or resumes) a payment attempt for a purchase.
     *
     * If the purchase already has an open (pending) attempt, that same
     * attempt is returned instead of creating a new one — handles a user
     * double-clicking "pay" or returning to an in-progress payment.
     *
     * A purchase that previously failed may retry, per the state table
     * (failed -> pending on retry) — this re-opens the hold and re-checks
     * availability under the service row lock, since the original spot may
     * since have been taken by someone else while this purchase sat failed.
     *
     * @throws PurchaseNotPayableException
     * @throws NoAvailabilityException
     */
    public function execute(Purchase $purchase): PaymentAttempt
    {
        // Up to 3 attempts: retries handle transient InnoDB deadlocks.
        // Str::uuid() for provider_reference is generated inside the
        // closure, so each retry produces a fresh unique value. Safe.
        return DB::transaction(function () use ($purchase) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Purchase::STATUS_PENDING && ! $locked->isHoldExpired()) {
                $openAttempt = $locked->currentAttempt;
                if ($openAttempt !== null && $openAttempt->status === PaymentAttempt::STATUS_PENDING) {
                    return $openAttempt;
                }

                return $this->createAttempt($locked);
            }

            if ($locked->status === Purchase::STATUS_FAILED) {
                $this->reopenHold($locked);

                return $this->createAttempt($locked);
            }

            throw new PurchaseNotPayableException();
        }, 3);
    }

    /**
     * @throws NoAvailabilityException
     */
    private function reopenHold(Purchase $purchase): void
    {
        /** @var Service $service */
        $service = Service::query()->whereKey($purchase->service_id)->lockForUpdate()->firstOrFail();

        $taken = Purchase::query()
            ->where('service_id', $service->id)
            ->whereKeyNot($purchase->id)
            ->whereIn('status', Purchase::OPEN_STATUSES)
            ->count();

        if ($taken >= $service->total_spots) {
            throw new NoAvailabilityException();
        }

        $purchase->forceFill([
            'status' => Purchase::STATUS_PENDING,
            'hold_expires_at' => now()->addMinutes($this->holdMinutes),
        ])->save();
    }

    private function createAttempt(Purchase $purchase): PaymentAttempt
    {
        $attemptNo = $purchase->attempts()->count() + 1;

        $attempt = PaymentAttempt::create([
            'purchase_id' => $purchase->id,
            'attempt_no' => $attemptNo,
            'provider_reference' => (string) Str::uuid(),
            'status' => PaymentAttempt::STATUS_PENDING,
        ]);

        $purchase->forceFill(['current_attempt_id' => $attempt->id])->save();

        return $attempt;
    }
}
