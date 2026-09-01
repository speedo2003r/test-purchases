<?php

namespace App\Actions;

use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Services\PurchaseStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProcessPaymentEventAction
{
    public function __construct(
        private readonly PurchaseStateMachine $stateMachine = new PurchaseStateMachine(),
    ) {
    }

    /**
     * Idempotently applies a payment provider event to its purchase.
     *
     * Safe to call any number of times with the same $providerEventId: the
     * first call applies the effect (if valid), every later call is a no-op
     * that returns the already-recorded event. Also safe against events
     * from a stale (non-current) payment attempt, and against events for
     * attempts whose purchase has already reached a terminal status.
     */
    public function execute(
        PaymentAttempt $attempt,
        string $providerEventId,
        string $eventType,
        \DateTimeInterface $occurredAt,
        array $rawPayload = [],
    ): PaymentEvent {
        // NOTE: DB::transaction() is intentionally called with the default
        // single attempt here (no retry count).
        //
        // Adding retries is NOT safe for this action: the transaction opens
        // by inserting a payment_events row in recordEventOrGetExisting().
        // If a deadlock rolls back the transaction and a retry re-runs the
        // closure, the second INSERT attempt for the same provider_event_id
        // hits the unique constraint, falls into the catch-and-re-fetch path,
        // and reads a row with processed_at = null (rolled-back state) —
        // causing the effect to be applied a second time and breaking
        // idempotency. The queue job's built-in Laravel retry handles
        // transient failures correctly: each job retry is a fresh invocation,
        // not a partial re-run of a half-committed transaction.
        return DB::transaction(function () use ($attempt, $providerEventId, $eventType, $occurredAt, $rawPayload) {
            $event = $this->recordEventOrGetExisting($attempt, $providerEventId, $eventType, $occurredAt, $rawPayload);

            if ($event->processed_at !== null) {
                // Already processed on a prior delivery — pure duplicate, no-op.
                return $event;
            }

            /** @var Purchase $purchase */
            $purchase = Purchase::query()->whereKey($attempt->purchase_id)->lockForUpdate()->firstOrFail();

            if ($purchase->current_attempt_id !== $attempt->id) {
                // Event belongs to an attempt that is no longer the purchase's
                // current attempt (a retry superseded it). Stale by identity,
                // regardless of the event's own timestamp.
                return $this->markProcessed($event);
            }

            if ($purchase->status === Purchase::STATUS_PENDING && $purchase->isHoldExpired()) {
                // The hold lapsed before this result arrived. The reserved
                // spot may already have been handed to someone else by the
                // expiry sweep; a late success cannot be honoured without
                // risking an oversell. Expire the purchase now and drop the
                // event's effect — it is still recorded above for audit.
                $purchase->forceFill(['status' => Purchase::STATUS_CANCELLED])->save();

                return $this->markProcessed($event);
            }

            $newStatus = $this->resolveNewStatus($purchase, $eventType);

            if ($newStatus !== null) {
                $purchase->forceFill(['status' => $newStatus])->save();
                $attempt->forceFill(['status' => $this->attemptStatusFor($eventType)])->save();
            }

            return $this->markProcessed($event);
        });
    }

    private function resolveNewStatus(Purchase $purchase, string $eventType): ?string
    {
        return $this->stateMachine->nextStatus($purchase->status, $eventType);
    }

    private function attemptStatusFor(string $eventType): string
    {
        return match ($eventType) {
            PaymentEvent::TYPE_SUCCESS => PaymentAttempt::STATUS_SUCCEEDED,
            PaymentEvent::TYPE_FAILED => PaymentAttempt::STATUS_FAILED,
            PaymentEvent::TYPE_CANCELLED => PaymentAttempt::STATUS_CANCELLED,
            default => PaymentAttempt::STATUS_FAILED,
        };
    }

    private function recordEventOrGetExisting(
        PaymentAttempt $attempt,
        string $providerEventId,
        string $eventType,
        \DateTimeInterface $occurredAt,
        array $rawPayload,
    ): PaymentEvent {
        try {
            return PaymentEvent::create([
                'payment_attempt_id' => $attempt->id,
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'raw_payload' => $rawPayload,
            ]);
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return PaymentEvent::query()->where('provider_event_id', $providerEventId)->firstOrFail();
            }

            throw $e;
        }
    }

    private function markProcessed(PaymentEvent $event): PaymentEvent
    {
        $event->forceFill(['processed_at' => now()])->save();

        return $event;
    }
}
