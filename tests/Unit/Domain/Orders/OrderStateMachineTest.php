<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Orders;

use PaxofiCloud\Domain\Orders\DocumentNumber;
use PaxofiCloud\Domain\Orders\InvalidTransition;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Orders\OrderStatus;
use PHPUnit\Framework\TestCase;

/** SRS §5.1, §5.3, ORD-001, INV-002, INV-003; commerce CD-25, C-20. */
final class OrderStateMachineTest extends TestCase
{
    public function testOrderTransitionsAreExactlyTheSrsTable(): void
    {
        $allowed = [];
        foreach (OrderStatus::cases() as $from) {
            foreach ($from->next() as $to) {
                $allowed[] = $from->value . '>' . $to->value;
            }
        }
        sort($allowed);
        self::assertSame([
            'fraud_review>cancelled', 'fraud_review>paid',
            'fulfilling>failed', 'fulfilling>fulfilled', 'fulfilling>partially_fulfilled',
            'paid>fraud_review', 'paid>fulfilling',
            'pending_payment>cancelled', 'pending_payment>expired', 'pending_payment>paid',
        ], $allowed);
    }

    public function testPendingPaymentCannotJumpToFulfilled(): void
    {
        // ORD-001 acceptance.
        $this->expectException(InvalidTransition::class);
        OrderStatus::PendingPayment->to(OrderStatus::Fulfilled);
    }

    public function testInvoiceTransitionsAndPaidInvoicesOnlyBecomeRefunded(): void
    {
        self::assertSame(InvoiceStatus::Paid, InvoiceStatus::Unpaid->to(InvoiceStatus::Paid));
        self::assertSame(InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid->to(InvoiceStatus::Paid));
        self::assertSame([InvoiceStatus::Refunded], InvoiceStatus::Paid->next());
        $this->expectException(InvalidTransition::class);
        InvoiceStatus::Paid->to(InvoiceStatus::Unpaid);
    }

    public function testDocumentNumbers(): void
    {
        self::assertSame('PXC-2027-000123', DocumentNumber::invoice(2027, 123));
        self::assertSame('PXO-2026-1000000', DocumentNumber::order(2026, 1_000_000));
        $this->expectException(\InvalidArgumentException::class);
        DocumentNumber::invoice(2026, 0);
    }
}
