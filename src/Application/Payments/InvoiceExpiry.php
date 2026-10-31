<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\InvoiceExpiryStore;

/**
 * Payments worker step (THREAT-MODEL-PAYMENTS.md PY-16; CRT-006, ORD-006): an
 * unpaid new-order invoice whose expiry has passed is cancelled, its order
 * expires and the invoice's issue entry is reversed, so the receivable is
 * gone. Only invoices with no open payment attempt are touched; open
 * attempts are settled first by the scheduled checks (at most 24 hours after
 * they started), so a payment on its way is never thrown away. A payment
 * that still succeeds afterwards is real money and goes to the customer's
 * credit (PY-12), and the order is not revived (PT-13).
 */
final readonly class InvoiceExpiry
{
    public const int MAX_PER_RUN = 20;

    public function __construct(
        private InvoiceExpiryStore $invoices,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /** @return int invoices expired */
    public function runOnce(): int
    {
        $expired = 0;
        foreach ($this->invoices->dueForExpiry($this->clock->now(), self::MAX_PER_RUN) as $due) {
            try {
                if (!$this->invoices->expire($due['invoice_id'], $this->clock->now())) {
                    continue;
                }
            } catch (\Throwable $e) {
                // The transaction rolled back; the next run tries again.
                $this->logger->error('orders.expire_error', ['invoice' => $due['invoice_id'], 'error' => $e::class]);
                continue;
            }
            $expired++;
            $this->audit->record(AuditEventType::ORDER_EXPIRED, AuditEvent::SUCCESS, ClientContext::ip('0.0.0.0'), accountId: $due['account_id'], details: [
                'order' => $due['order_id'],
                'invoice' => $due['invoice_id'],
            ]);
        }

        return $expired;
    }
}
