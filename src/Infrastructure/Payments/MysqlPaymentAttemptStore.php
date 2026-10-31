<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Payments;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\PaymentAttemptStore;
use PaxofiCloud\Application\Payments\AttemptToStart;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentAttemptState;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * Payment attempts in MySQL (THREAT-MODEL-PAYMENTS.md PY-5, PY-6). Customer
 * reads carry the account in the WHERE clause; state changes are
 * conditional UPDATEs on the current state, so they happen at most once.
 */
final readonly class MysqlPaymentAttemptStore implements PaymentAttemptStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';
    private const string REASON = '/^[a-z][a-z_]{1,31}$/';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function payableInvoice(string $accountId, string $orderId): ?PayableInvoice
    {
        $row = $this->repository->fetchAll(
            'SELECT id, number, status, currency, total_minor, expires_at FROM invoices WHERE account_id = :account AND order_id = :order',
            ['account' => $accountId, 'order' => $orderId],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }

        // No payment is recorded against an invoice yet (PY-9 comes next), so its balance is its total.
        return new PayableInvoice(
            Row::string($row, 'id'),
            Row::string($row, 'number'),
            InvoiceStatus::from(Row::string($row, 'status')),
            Money::of(Row::int($row, 'total_minor'), Row::string($row, 'currency')),
            self::time(Row::string($row, 'expires_at')),
        );
    }

    public function connectedGateways(string $environment): array
    {
        $gateways = [];
        foreach ($this->repository->fetchAll(
            'SELECT provider FROM payment_gateway_credentials WHERE environment = :environment ORDER BY provider',
            ['environment' => $environment],
        ) as $row) {
            $gateway = Gateway::tryFrom(Row::string($row, 'provider'));
            if ($gateway !== null) {
                $gateways[] = $gateway;
            }
        }

        return $gateways;
    }

    public function openAttempts(string $invoiceId): int
    {
        return Row::int($this->repository->fetchAll(
            "SELECT COUNT(*) AS n FROM payment_attempts WHERE invoice_id = :invoice AND state IN ('initiated', 'pending', 'unknown')",
            ['invoice' => $invoiceId],
        )[0] ?? [], 'n');
    }

    public function findByIdempotencyKey(string $accountId, string $key): ?array
    {
        $row = $this->repository->fetchAll(
            'SELECT id, request_hash FROM payment_attempts WHERE account_id = :account AND idempotency_key = :key',
            ['account' => $accountId, 'key' => $key],
        )[0] ?? null;

        return $row === null ? null : ['id' => Row::string($row, 'id'), 'request_hash' => Row::string($row, 'request_hash')];
    }

    public function create(NewPaymentAttempt $attempt): void
    {
        $at = self::utc($attempt->createdAt);
        try {
            $this->connection->execute(
                'INSERT INTO payment_attempts (id, reference, account_id, invoice_id, initiated_by, gateway, state, amount_minor, currency,
                     idempotency_key, request_hash, created_at, state_changed_at)
                 VALUES (:id, :reference, :account, :invoice, :identity, :gateway, :state, :amount, :currency, :key, :hash, :created, :changed)',
                [
                    'id' => $attempt->id,
                    'reference' => $attempt->reference->value,
                    'account' => $attempt->accountId,
                    'invoice' => $attempt->invoiceId,
                    'identity' => $attempt->initiatedBy,
                    'gateway' => $attempt->gateway->value,
                    'state' => PaymentAttemptState::Initiated->value,
                    'amount' => $attempt->amount->minorUnits,
                    'currency' => $attempt->amount->currency->value,
                    'key' => $attempt->idempotencyKey,
                    'hash' => $attempt->requestHash,
                    'created' => $at,
                    'changed' => $at,
                ],
            );
        } catch (\RuntimeException $e) {
            // PCF wraps the driver error; look for the unique-key violation on the idempotency index.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException && ($cause->errorInfo[1] ?? null) === 1062 && str_contains($cause->getMessage(), 'uq_payment_attempts_idempotency')) {
                    throw new DuplicateIdempotencyKey('Idempotency key already used.', 0, $e);
                }
            }
            throw $e;
        }
    }

    public function find(string $accountId, string $attemptId): ?array
    {
        $row = $this->repository->fetchAll(
            'SELECT a.id, a.reference, a.gateway, a.state, a.amount_minor, a.currency, a.checkout_url, a.failure_reason, a.created_at,
                    i.number AS invoice_number, i.order_id
             FROM payment_attempts a JOIN invoices i ON i.id = a.invoice_id AND i.account_id = a.account_id
             WHERE a.account_id = :account AND a.id = :id',
            ['account' => $accountId, 'id' => $attemptId],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $state = Row::string($row, 'state');
        $url = $row['checkout_url'] ?? null;
        $reason = $row['failure_reason'] ?? null;

        return [
            'id' => Row::string($row, 'id'),
            'reference' => Row::string($row, 'reference'),
            'gateway' => Row::string($row, 'gateway'),
            'state' => $state,
            'amount' => Row::int($row, 'amount_minor'),
            'currency' => Row::string($row, 'currency'),
            'invoice_number' => Row::string($row, 'invoice_number'),
            'order_id' => Row::string($row, 'order_id'),
            // Only a pending attempt has a page worth going to.
            'checkout_url' => $state === PaymentAttemptState::Pending->value && is_string($url) ? $url : null,
            'failure_reason' => is_string($reason) ? $reason : null,
            'created_at' => self::time(Row::string($row, 'created_at'))->format(\DATE_ATOM),
        ];
    }

    public function claimNext(\DateTimeImmutable $now): ?AttemptToStart
    {
        $cutoff = self::utc($now->modify('-' . self::CLAIM_TIMEOUT_SECONDS . ' seconds'));
        // A few tries: another worker may take the row between the read and the claim.
        for ($try = 0; $try < 3; $try++) {
            $row = $this->repository->fetchAll(
                "SELECT a.id, a.account_id, a.reference, a.gateway, a.amount_minor, a.currency, a.claimed_at, a.created_at, i.number AS invoice_number, p.email
                 FROM payment_attempts a
                 JOIN invoices i ON i.id = a.invoice_id AND i.account_id = a.account_id
                 JOIN identities p ON p.id = a.initiated_by
                 WHERE a.state = 'initiated' AND (a.claimed_at IS NULL OR a.claimed_at < :cutoff)
                 ORDER BY a.created_at, a.id LIMIT 1",
                ['cutoff' => $cutoff],
            )[0] ?? null;
            if ($row === null) {
                return null;
            }
            $previous = $row['claimed_at'] ?? null;
            $claimed = $this->connection->execute(
                "UPDATE payment_attempts SET claimed_at = :now
                 WHERE id = :id AND state = 'initiated' AND " . (is_string($previous) ? 'claimed_at = :previous' : 'claimed_at IS NULL'),
                ['now' => self::utc($now), 'id' => Row::string($row, 'id')] + (is_string($previous) ? ['previous' => $previous] : []),
            );
            if ($claimed !== 1) {
                continue;
            }

            return new AttemptToStart(
                Row::string($row, 'id'),
                Row::string($row, 'account_id'),
                PaymentReference::fromString(Row::string($row, 'reference')),
                Gateway::from(Row::string($row, 'gateway')),
                Money::of(Row::int($row, 'amount_minor'), Row::string($row, 'currency')),
                Row::string($row, 'invoice_number'),
                Row::string($row, 'email'),
                self::time(Row::string($row, 'created_at')),
                is_string($previous),
            );
        }

        return null;
    }

    public function markCheckoutReady(string $attemptId, string $checkoutUrl, \DateTimeImmutable $now): bool
    {
        if (!str_starts_with($checkoutUrl, 'https://') || strlen($checkoutUrl) > 512) {
            throw new \InvalidArgumentException('A checkout URL is a plain https URL.');
        }

        return $this->connection->execute(
            "UPDATE payment_attempts SET state = :state, checkout_url = :url, state_changed_at = :at WHERE id = :id AND state = 'initiated'",
            ['state' => PaymentAttemptState::Initiated->to(PaymentAttemptState::Pending)->value, 'url' => $checkoutUrl, 'at' => self::utc($now), 'id' => $attemptId],
        ) === 1;
    }

    public function markFailed(string $attemptId, string $reason, \DateTimeImmutable $now): bool
    {
        if (preg_match(self::REASON, $reason) !== 1) {
            throw new \InvalidArgumentException('Failure reasons are fixed codes.');
        }

        return $this->connection->execute(
            "UPDATE payment_attempts SET state = :state, failure_reason = :reason, state_changed_at = :at WHERE id = :id AND state = 'initiated'",
            ['state' => PaymentAttemptState::Initiated->to(PaymentAttemptState::Failed)->value, 'reason' => $reason, 'at' => self::utc($now), 'id' => $attemptId],
        ) === 1;
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    private static function time(string $mysqlUtc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($mysqlUtc, new \DateTimeZone('UTC'));
    }
}
