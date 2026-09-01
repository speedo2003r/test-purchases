<?php

namespace Tests\Unit;

use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Services\PurchaseStateMachine;
use PHPUnit\Framework\TestCase;

class PurchaseStateMachineTest extends TestCase
{
    private PurchaseStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new PurchaseStateMachine();
    }

    public function test_pending_success_confirms(): void
    {
        $this->assertSame(
            Purchase::STATUS_CONFIRMED,
            $this->machine->nextStatus(Purchase::STATUS_PENDING, PaymentEvent::TYPE_SUCCESS),
        );
    }

    public function test_pending_failed_fails(): void
    {
        $this->assertSame(
            Purchase::STATUS_FAILED,
            $this->machine->nextStatus(Purchase::STATUS_PENDING, PaymentEvent::TYPE_FAILED),
        );
    }

    public function test_pending_cancelled_cancels(): void
    {
        $this->assertSame(
            Purchase::STATUS_CANCELLED,
            $this->machine->nextStatus(Purchase::STATUS_PENDING, PaymentEvent::TYPE_CANCELLED),
        );
    }

    /**
     * Confirmed and cancelled are terminal — no event moves them further.
     * This directly covers the assignment's explicit warning: do not assume
     * SUCCESS -> FAILED is valid.
     */
    public function test_confirmed_is_terminal_for_all_event_types(): void
    {
        foreach ([PaymentEvent::TYPE_SUCCESS, PaymentEvent::TYPE_FAILED, PaymentEvent::TYPE_CANCELLED] as $type) {
            $this->assertNull($this->machine->nextStatus(Purchase::STATUS_CONFIRMED, $type));
        }
    }

    public function test_cancelled_is_terminal_for_all_event_types(): void
    {
        foreach ([PaymentEvent::TYPE_SUCCESS, PaymentEvent::TYPE_FAILED, PaymentEvent::TYPE_CANCELLED] as $type) {
            $this->assertNull($this->machine->nextStatus(Purchase::STATUS_CANCELLED, $type));
        }
    }

    /**
     * Failed is terminal at the generic-transition-table level; the narrow
     * "late success after failed" business exception is handled explicitly
     * by ProcessPaymentEventAction, not by this table.
     */
    public function test_failed_is_terminal_for_all_event_types(): void
    {
        foreach ([PaymentEvent::TYPE_SUCCESS, PaymentEvent::TYPE_FAILED, PaymentEvent::TYPE_CANCELLED] as $type) {
            $this->assertNull($this->machine->nextStatus(Purchase::STATUS_FAILED, $type));
        }
    }
}
