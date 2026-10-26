<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Orders;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Checkout\NewOrder;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\Ledger;
use PaxofiCloud\Application\Contracts\OrderStore;
use PaxofiCloud\Domain\Ledger\Postings;
use PaxofiCloud\Domain\Orders\DocumentNumber;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Orders\OrderStatus;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL orders and invoices (migration 0024; commerce threat model CD-24,
 * CD-26). One transaction writes everything; numbers come from a row-locked
 * per-year sequence, so they are gap-free (INV-002), and the same
 * transaction locks the account's billing currency (CD-21) and posts the
 * invoice's issue entry to the ledger (PY-13), so an invoice never exists
 * without its receivable. Reads always filter by account in SQL.
 *
 * @phpstan-import-type OrderRow from OrderStore
 * @phpstan-import-type OrderLineRow from OrderStore
 */
final readonly class MysqlOrderStore implements OrderStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';
    /** Newest orders an account can page through; older ones arrive with the billing history page (Sprint 3). */
    public const int LIST_CAP = 200;
    private const string SELECT = 'SELECT o.id, o.number, o.status, o.currency, o.subtotal_minor, o.tax_minor, o.total_minor, o.tax_name, o.tax_rate_bp, o.placed_at, o.expires_at,
            i.number AS invoice_number, i.status AS invoice_status
        FROM orders o JOIN invoices i ON i.order_id = o.id AND i.account_id = o.account_id';

    public function __construct(private Connection $connection, private Repository $repository, private TransactionManager $transactions, private Ledger $ledger)
    {
    }

    public function findByIdempotencyKey(string $accountId, string $key): ?array
    {
        $row = $this->repository->fetchAll(
            'SELECT id, request_hash FROM orders WHERE account_id = :account AND idempotency_key = :key',
            ['account' => $accountId, 'key' => $key],
        )[0] ?? null;

        return $row === null ? null : ['id' => Row::string($row, 'id'), 'request_hash' => Row::string($row, 'request_hash')];
    }

    public function place(NewOrder $order): array
    {
        try {
            /** @var array{order_number: string, invoice_number: string} */
            return $this->transactions->transaction(function () use ($order): array {
                $year = (int) $order->placedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y');
                $orderNumber = DocumentNumber::order($year, $this->next('order', $year));
                $invoiceNumber = DocumentNumber::invoice($year, $this->next('invoice', $year));
                $placed = self::utc($order->placedAt);
                $expires = self::utc($order->expiresAt);
                $subtotal = $order->subtotalMinor();
                $tax = $order->taxMinor();

                $this->connection->execute(
                    'INSERT INTO orders (id, number, account_id, placed_by, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                         billing_country, visitor_country, flagged_for_review, terms_version, idempotency_key, request_hash, placed_at, expires_at, status_changed_at)
                     VALUES (:id, :number, :account, :identity, :status, :currency, :subtotal, :tax, :total, :tax_country, :tax_name, :tax_bp,
                         :billing_country, :visitor_country, :flagged, :terms, :key, :hash, :placed, :expires, :changed)',
                    [
                        'id' => $order->orderId,
                        'number' => $orderNumber,
                        'account' => $order->accountId,
                        'identity' => $order->identityId,
                        'status' => OrderStatus::PendingPayment->value,
                        'currency' => $order->currency->value,
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $subtotal + $tax,
                        'tax_country' => $order->tax->country,
                        'tax_name' => $order->tax->name,
                        'tax_bp' => $order->tax->rate->value,
                        'billing_country' => $order->billTo->country,
                        'visitor_country' => $order->visitorCountry,
                        'flagged' => $order->flaggedForReview ? 1 : 0,
                        'terms' => $order->termsVersion,
                        'key' => $order->idempotencyKey,
                        'hash' => $order->requestHash,
                        'placed' => $placed,
                        'expires' => $expires,
                        'changed' => $placed,
                    ],
                );
                foreach ($order->lines as $i => $line) {
                    $this->connection->execute(
                        'INSERT INTO order_lines (order_id, line_no, kind, domain, years, price_minor, tax_minor, total_minor, renewal_price_minor, registrar, cost_minor, cost_currency, fx_set_id)
                         VALUES (:order, :no, \'domain_register\', :domain, 1, :price, :tax, :total, :renew, :registrar, :cost, :cost_currency, :fx)',
                        [
                            'order' => $order->orderId,
                            'no' => $i + 1,
                            'domain' => $line->domain,
                            'price' => $line->priceMinor,
                            'tax' => $line->taxMinor,
                            'total' => $line->totalMinor(),
                            'renew' => $line->renewalPriceMinor,
                            'registrar' => $line->registrar,
                            'cost' => $line->costMinor,
                            'cost_currency' => $line->costCurrency,
                            'fx' => $line->fxSetId,
                        ],
                    );
                }
                $b = $order->billTo;
                $this->connection->execute(
                    'INSERT INTO invoices (id, number, account_id, order_id, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                         bill_to_name, bill_to_company, bill_to_line1, bill_to_line2, bill_to_city, bill_to_region, bill_to_postcode, bill_to_country, bill_to_phone, bill_to_tin,
                         issued_at, due_at, expires_at, status_changed_at)
                     VALUES (:id, :number, :account, :order, :status, :currency, :subtotal, :tax, :total, :tax_country, :tax_name, :tax_bp,
                         :name, :company, :line1, :line2, :city, :region, :postcode, :country, :phone, :tin, :issued, :due, :expires, :changed)',
                    [
                        'id' => $order->invoiceId,
                        'number' => $invoiceNumber,
                        'account' => $order->accountId,
                        'order' => $order->orderId,
                        'status' => InvoiceStatus::Unpaid->value,
                        'currency' => $order->currency->value,
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $subtotal + $tax,
                        'tax_country' => $order->tax->country,
                        'tax_name' => $order->tax->name,
                        'tax_bp' => $order->tax->rate->value,
                        'name' => $b->fullName,
                        'company' => $b->company,
                        'line1' => $b->addressLine1,
                        'line2' => $b->addressLine2,
                        'city' => $b->city,
                        'region' => $b->region,
                        'postcode' => $b->postcode,
                        'country' => $b->country,
                        'phone' => $b->phone,
                        'tin' => $b->tin,
                        'issued' => $placed,
                        // BR-10: new-order invoices are due immediately and expire after 72 hours.
                        'due' => $placed,
                        'expires' => $expires,
                        'changed' => $placed,
                    ],
                );
                foreach ($order->lines as $i => $line) {
                    $this->connection->execute(
                        'INSERT INTO invoice_lines (invoice_id, line_no, description, amount_minor, tax_minor, total_minor) VALUES (:invoice, :no, :description, :amount, :tax, :total)',
                        [
                            'invoice' => $order->invoiceId,
                            'no' => $i + 1,
                            'description' => 'Domain registration: ' . $line->domain . ' (1 year)',
                            'amount' => $line->priceMinor,
                            'tax' => $line->taxMinor,
                            'total' => $line->totalMinor(),
                        ],
                    );
                }
                $this->ledger->postInCurrentTransaction(Postings::invoiceIssued(
                    bin2hex(random_bytes(16)),
                    $order->invoiceId,
                    $order->accountId,
                    $order->currency,
                    $subtotal,
                    $tax,
                    $order->placedAt,
                ));
                // The first invoice fixes the billing currency (PRC-003).
                $this->connection->execute(
                    'UPDATE account_billing_details SET currency_locked_at = COALESCE(currency_locked_at, :at) WHERE account_id = :account',
                    ['at' => $placed, 'account' => $order->accountId],
                );

                return ['order_number' => $orderNumber, 'invoice_number' => $invoiceNumber];
            });
        } catch (\RuntimeException $e) {
            // PCF wraps the driver error; look for the unique-key violation on the idempotency index.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException && ($cause->errorInfo[1] ?? null) === 1062 && str_contains($cause->getMessage(), 'uq_orders_idempotency')) {
                    throw new DuplicateIdempotencyKey('Idempotency key already used.', 0, $e);
                }
            }
            throw $e;
        }
    }

    public function find(string $accountId, string $orderId): ?array
    {
        return $this->rows($this->repository->fetchAll(
            self::SELECT . ' WHERE o.account_id = :account AND o.id = :id',
            ['account' => $accountId, 'id' => $orderId],
        ))[0] ?? null;
    }

    public function forAccount(string $accountId, int $limit, int $offset): array
    {
        $rows = $this->repository->fetchAll(
            self::SELECT . ' WHERE o.account_id = :account ORDER BY o.placed_at DESC, o.number DESC LIMIT ' . self::LIST_CAP,
            ['account' => $accountId],
        );

        return $this->rows(array_slice($rows, max(0, $offset), max(1, min(50, $limit))));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<OrderRow>
     */
    private function rows(array $rows): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $orders[] = [
                'id' => $id,
                'number' => Row::string($row, 'number'),
                'status' => Row::string($row, 'status'),
                'currency' => Row::string($row, 'currency'),
                'subtotal' => Row::int($row, 'subtotal_minor'),
                'tax' => Row::int($row, 'tax_minor'),
                'total' => Row::int($row, 'total_minor'),
                'tax_name' => Row::string($row, 'tax_name'),
                'tax_rate_bp' => Row::int($row, 'tax_rate_bp'),
                'placed_at' => self::atom(Row::string($row, 'placed_at')),
                'expires_at' => self::atom(Row::string($row, 'expires_at')),
                'invoice_number' => Row::string($row, 'invoice_number'),
                'invoice_status' => Row::string($row, 'invoice_status'),
                'lines' => $this->lines($id),
            ];
        }

        return $orders;
    }

    /** @return list<OrderLineRow> */
    private function lines(string $orderId): array
    {
        $lines = [];
        foreach ($this->repository->fetchAll(
            'SELECT domain, years, price_minor, tax_minor, total_minor, renewal_price_minor FROM order_lines WHERE order_id = :id ORDER BY line_no',
            ['id' => $orderId],
        ) as $row) {
            $lines[] = [
                'domain' => Row::string($row, 'domain'),
                'years' => Row::int($row, 'years'),
                'price' => Row::int($row, 'price_minor'),
                'tax' => Row::int($row, 'tax_minor'),
                'total' => Row::int($row, 'total_minor'),
                'renewal_price' => Row::int($row, 'renewal_price_minor'),
            ];
        }

        return $lines;
    }

    /** Next number for the kind and year; the upsert holds the row lock until the transaction ends. */
    private function next(string $kind, int $year): int
    {
        $this->connection->execute(
            'INSERT INTO document_sequences (kind, year, seq_value) VALUES (:kind, :year, 1) ON DUPLICATE KEY UPDATE seq_value = seq_value + 1',
            ['kind' => $kind, 'year' => $year],
        );

        return Row::int($this->repository->fetchAll('SELECT seq_value FROM document_sequences WHERE kind = :kind AND year = :year', ['kind' => $kind, 'year' => $year])[0] ?? [], 'seq_value');
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    private static function atom(string $mysqlUtc): string
    {
        return (new \DateTimeImmutable($mysqlUtc, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
