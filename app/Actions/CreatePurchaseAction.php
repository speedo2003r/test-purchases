<?php

namespace App\Actions;

use App\Exceptions\NoAvailabilityException;
use App\Exceptions\ServiceNotAvailableException;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreatePurchaseAction
{
    public function __construct(
        private readonly int $holdMinutes = 15,
    ) {
    }

    /**
     * Creates a purchase for the given service, reserving one spot.
     *
     * Idempotent on request_key: a repeated call with the same key returns
     * the existing purchase instead of creating a duplicate (handles
     * double-click / refresh-resubmit).
     *
     * @throws NoAvailabilityException
     * @throws ServiceNotAvailableException
     */
    public function execute(User $user, Service $service, string $requestKey): Purchase
    {
        $existing = Purchase::query()->where('request_key', $requestKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->insertPurchase($user, $service, $requestKey);
        } catch (QueryException $e) {
            // Unique constraint race: another concurrent call with the same
            // request_key won the insert first. Same user action, same
            // result — return their purchase instead of failing.
            if ($this->isUniqueConstraintViolation($e)) {
                return Purchase::query()->where('request_key', $requestKey)->firstOrFail();
            }

            throw $e;
        }
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (int) $e->getCode() === 23000;
    }

    /**
     * @throws NoAvailabilityException
     * @throws ServiceNotAvailableException
     */
    private function insertPurchase(User $user, Service $service, string $requestKey): Purchase
    {
        // Up to 3 attempts: retries transparently handle transient InnoDB
        // deadlocks and lock-wait timeouts without caller involvement.
        // The closure has no external side effects before the final
        // Purchase::create(), so retrying is safe. If a retry hits the
        // unique request_key constraint, the outer catch in execute()
        // recovers it correctly.
        return DB::transaction(function () use ($user, $service, $requestKey) {
            /** @var Service $lockedService */
            $lockedService = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if (! $lockedService->isWithinAvailabilityWindow()) {
                throw new ServiceNotAvailableException();
            }

            // Lazily release any holds that expired before anyone got around
            // to sweeping them, so this check always sees accurate capacity.
            Purchase::query()
                ->where('service_id', $lockedService->id)
                ->where('status', Purchase::STATUS_PENDING)
                ->where('hold_expires_at', '<', now())
                ->update(['status' => Purchase::STATUS_CANCELLED]);

            $taken = Purchase::query()
                ->where('service_id', $lockedService->id)
                ->whereIn('status', Purchase::OPEN_STATUSES)
                ->count();

            if ($taken >= $lockedService->total_spots) {
                throw new NoAvailabilityException();
            }

            return Purchase::create([
                'user_id' => $user->id,
                'service_id' => $lockedService->id,
                'status' => Purchase::STATUS_PENDING,
                'request_key' => $requestKey,
                'hold_expires_at' => now()->addMinutes($this->holdMinutes),
            ]);
        }, 3);
    }
}
