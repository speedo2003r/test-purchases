<?php

namespace App\Services;

use App\Models\PaymentEvent;
use App\Models\Purchase;

/**
 * Pure transition guard for Purchase status changes driven by payment events.
 *
 * Attempt-currency (is this event's attempt still the purchase's current
 * attempt?) and hold-expiry are checked by the caller before consulting this
 * table — this class only answers "given the purchase is currently in status
 * X, does event type Y move it to a new status, or is it a no-op?".
 */
class PurchaseStateMachine
{
    /**
     * Returns the new status, or null if the event should be a no-op
     * (duplicate, stale, or invalid for the current status).
     */
    public function nextStatus(string $currentStatus, string $eventType): ?string
    {
        return match ($currentStatus) {
            Purchase::STATUS_PENDING => match ($eventType) {
                PaymentEvent::TYPE_SUCCESS => Purchase::STATUS_CONFIRMED,
                PaymentEvent::TYPE_FAILED => Purchase::STATUS_FAILED,
                PaymentEvent::TYPE_CANCELLED => Purchase::STATUS_CANCELLED,
                default => null,
            },
            // confirmed and cancelled are terminal: no event moves them further.
            Purchase::STATUS_CONFIRMED, Purchase::STATUS_CANCELLED => null,
            // failed is terminal for events (a late success is a special case
            // handled explicitly by the caller, not a generic transition here).
            Purchase::STATUS_FAILED => null,
            default => null,
        };
    }
}
