<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\PaymentAttemptStore;
use PaxofiCloud\Application\Payments\AttemptToStart;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Payments\Gateway;

/** Payment attempts kept in memory, with the same account scoping and conditional state changes as MySQL. */
final class InMemoryPaymentAttemptStore implements PaymentAttemptStore
{
    /** @var array<string, array{account: string, order: string, invoice: PayableInvoice}> keyed by order ID */
    public array $invoices = [];
    /** @var array<string, list<Gateway>> environment => gateways */
    public array $connected = [];
    /** @var array<string, array{attempt: NewPaymentAttempt, state: string, url: ?string, reason: ?string, claimed: ?\DateTimeImmutable}> */
    public array $attempts = [];
    /** @var array<string, string> identity ID => email */
    public array $emails = [];
    /** Simulates a concurrent request that inserts the same key first. */
    public bool $raceOnCreate = false;

    public function addInvoice(string $accountId, string $orderId, PayableInvoice $invoice): void
    {
        $this->invoices[$orderId] = ['account' => $accountId, 'order' => $orderId, 'invoice' => $invoice];
    }

    public function payableInvoice(string $accountId, string $orderId): ?PayableInvoice
    {
        $row = $this->invoices[$orderId] ?? null;

        return $row !== null && $row['account'] === $accountId ? $row['invoice'] : null;
    }

    public function connectedGateways(string $environment): array
    {
        return $this->connected[$environment] ?? [];
    }

    public function openAttempts(string $invoiceId): int
    {
        return count(array_filter(
            $this->attempts,
            static fn (array $a): bool => $a['attempt']->invoiceId === $invoiceId && in_array($a['state'], ['initiated', 'pending', 'unknown'], true),
        ));
    }

    public function findByIdempotencyKey(string $accountId, string $key): ?array
    {
        foreach ($this->attempts as $id => $a) {
            if ($a['attempt']->accountId === $accountId && $a['attempt']->idempotencyKey === $key) {
                return ['id' => $id, 'request_hash' => $a['attempt']->requestHash];
            }
        }

        return null;
    }

    public function create(NewPaymentAttempt $attempt): void
    {
        if ($this->raceOnCreate) {
            $this->raceOnCreate = false;
            $this->attempts[str_repeat('e', 32)] = ['attempt' => new NewPaymentAttempt(
                str_repeat('e', 32), $attempt->reference, $attempt->accountId, $attempt->invoiceId, $attempt->initiatedBy,
                $attempt->gateway, $attempt->amount, $attempt->idempotencyKey, $attempt->requestHash, $attempt->createdAt,
            ), 'state' => 'initiated', 'url' => null, 'reason' => null, 'claimed' => null];
            throw new DuplicateIdempotencyKey('Idempotency key already used.');
        }
        if ($this->findByIdempotencyKey($attempt->accountId, $attempt->idempotencyKey) !== null) {
            throw new DuplicateIdempotencyKey('Idempotency key already used.');
        }
        $this->attempts[$attempt->id] = ['attempt' => $attempt, 'state' => 'initiated', 'url' => null, 'reason' => null, 'claimed' => null];
    }

    public function find(string $accountId, string $attemptId): ?array
    {
        $a = $this->attempts[$attemptId] ?? null;
        if ($a === null || $a['attempt']->accountId !== $accountId) {
            return null;
        }
        $order = '';
        $number = '';
        foreach ($this->invoices as $row) {
            if ($row['invoice']->invoiceId === $a['attempt']->invoiceId) {
                $order = $row['order'];
                $number = $row['invoice']->number;
            }
        }

        return [
            'id' => $attemptId,
            'reference' => $a['attempt']->reference->value,
            'gateway' => $a['attempt']->gateway->value,
            'state' => $a['state'],
            'amount' => $a['attempt']->amount->minorUnits,
            'currency' => $a['attempt']->amount->currency->value,
            'invoice_number' => $number,
            'order_id' => $order,
            'checkout_url' => $a['state'] === 'pending' ? $a['url'] : null,
            'failure_reason' => $a['reason'],
            'created_at' => $a['attempt']->createdAt->format(\DATE_ATOM),
        ];
    }

    public function claimNext(\DateTimeImmutable $now): ?AttemptToStart
    {
        $cutoff = $now->modify('-' . self::CLAIM_TIMEOUT_SECONDS . ' seconds');
        foreach ($this->attempts as $id => $a) {
            if ($a['state'] !== 'initiated' || ($a['claimed'] !== null && $a['claimed'] >= $cutoff)) {
                continue;
            }
            $reclaimed = $a['claimed'] !== null;
            $this->attempts[$id]['claimed'] = $now;
            $number = '';
            foreach ($this->invoices as $row) {
                if ($row['invoice']->invoiceId === $a['attempt']->invoiceId) {
                    $number = $row['invoice']->number;
                }
            }

            return new AttemptToStart(
                $id,
                $a['attempt']->accountId,
                $a['attempt']->reference,
                $a['attempt']->gateway,
                $a['attempt']->amount,
                $number,
                $this->emails[$a['attempt']->initiatedBy] ?? 'payer@example.invalid',
                $a['attempt']->createdAt,
                $reclaimed,
            );
        }

        return null;
    }

    public function markCheckoutReady(string $attemptId, string $checkoutUrl, \DateTimeImmutable $now): bool
    {
        if (($this->attempts[$attemptId]['state'] ?? null) !== 'initiated') {
            return false;
        }
        $this->attempts[$attemptId]['state'] = 'pending';
        $this->attempts[$attemptId]['url'] = $checkoutUrl;

        return true;
    }

    public function markFailed(string $attemptId, string $reason, \DateTimeImmutable $now): bool
    {
        if (($this->attempts[$attemptId]['state'] ?? null) !== 'initiated') {
            return false;
        }
        $this->attempts[$attemptId]['state'] = 'failed';
        $this->attempts[$attemptId]['reason'] = $reason;

        return true;
    }
}
