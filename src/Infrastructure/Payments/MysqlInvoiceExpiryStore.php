<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Payments;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\InvoiceExpiryStore;
use PaxofiCloud\Application\Contracts\Ledger;
use PaxofiCloud\Domain\Ledger\Postings;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * Invoice expiry in MySQL (PY-16). The invoice update is conditional on its
 * state and on there being no open attempt at that moment, so a payment that
 * starts or settles concurrently wins; the ledger reversal is keyed by the
 * issue entry, so an invoice is reversed at most once.
 */
final readonly class MysqlInvoiceExpiryStore implements InvoiceExpiryStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';
    private const string NO_OPEN_ATTEMPT = "NOT EXISTS (SELECT 1 FROM payment_attempts a WHERE a.invoice_id = i.id AND a.state IN ('initiated', 'pending', 'unknown'))";

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
        private Ledger $ledger,
    ) {
    }

    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array
    {
        $due = [];
        foreach ($this->repository->fetchAll(
            "SELECT i.id, i.order_id, i.account_id FROM invoices i
             WHERE i.status = 'unpaid' AND i.expires_at <= :now AND " . self::NO_OPEN_ATTEMPT . '
             ORDER BY i.expires_at, i.id LIMIT ' . max(1, min(100, $limit)),
            ['now' => self::utc($now)],
        ) as $row) {
            $due[] = ['invoice_id' => Row::string($row, 'id'), 'order_id' => Row::string($row, 'order_id'), 'account_id' => Row::string($row, 'account_id')];
        }

        return $due;
    }

    public function expire(string $invoiceId, \DateTimeImmutable $now): bool
    {
        $at = self::utc($now);

        return (bool) $this->transactions->transaction(function () use ($invoiceId, $now, $at): bool {
            $cancelled = $this->connection->execute(
                "UPDATE invoices i SET i.status = 'cancelled', i.status_changed_at = :at
                 WHERE i.id = :id AND i.status = 'unpaid' AND i.expires_at <= :now AND " . self::NO_OPEN_ATTEMPT,
                ['at' => $at, 'now' => $at, 'id' => $invoiceId],
            ) === 1;
            if (!$cancelled) {
                return false;
            }
            $this->connection->execute(
                "UPDATE orders o JOIN invoices i ON i.order_id = o.id SET o.status = 'expired', o.status_changed_at = :at
                 WHERE i.id = :id AND o.status = 'pending_payment'",
                ['at' => $at, 'id' => $invoiceId],
            );
            $issued = $this->ledger->entryForSource(Postings::invoiceIssuedKey($invoiceId));
            if ($issued !== null) {
                $this->ledger->postInCurrentTransaction($issued->reversal(bin2hex(random_bytes(16)), $now));
            }

            return true;
        });
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }
}
