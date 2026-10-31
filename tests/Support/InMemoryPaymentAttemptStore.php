<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\PaymentAttemptStore;
use PaxofiCloud\Application\Payments\AttemptToStart;
use PaxofiCloud\Application\Payments\AttemptToVerify;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentAttemptState;

/** Payment attempts kept in memory, with the same account scoping and conditional state changes as MySQL. */
final class InMemoryPaymentAttemptStore implements PaymentAttemptStore
{
    /** @var array<string, array{account: string, order: string, invoice: PayableInvoice}> keyed by order ID */
    public array $invoices = [];
    /** @var array<string, list<Gateway>> environment => gateways */
    public array $connected = [];
    /** @var array<string, array{attempt: NewPaymentAttempt, state: string, url: ?string, reason: ?string, claimed: ?\DateTimeImmutable, checks: int, next: ?\DateTimeImmutable, paid: ?GatewayVerification}> */
    public array $attempts = [];
    /** @var list<array{kind: string, payment: string, minor: int, currency: string}> what the MySQL store would post to the ledger */
    public array $postings = [];
    /** Makes the next recordSuccess() throw, as a failed transaction would. */
    public bool $failNextRecord = false;
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
            ), 'state' => 'initiated', 'url' => null, 'reason' => null, 'claimed' => null, 'checks' => 0, 'next' => null, 'paid' => null];
            throw new DuplicateIdempotencyKey('Idempotency key already used.');
        }
        if ($this->findByIdempotencyKey($attempt->accountId, $attempt->idempotencyKey) !== null) {
            throw new DuplicateIdempotencyKey('Idempotency key already used.');
        }
        $this->attempts[$attempt->id] = ['attempt' => $attempt, 'state' => 'initiated', 'url' => null, 'reason' => null, 'claimed' => null, 'checks' => 0, 'next' => null, 'paid' => null];
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

    public function markCheckoutReady(string $attemptId, string $checkoutUrl, \DateTimeImmutable $now, \DateTimeImmutable $firstCheckAt): bool
    {
        if (($this->attempts[$attemptId]['state'] ?? null) !== 'initiated') {
            return false;
        }
        $this->attempts[$attemptId]['state'] = 'pending';
        $this->attempts[$attemptId]['url'] = $checkoutUrl;
        $this->attempts[$attemptId]['next'] = $firstCheckAt;

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
    public function dueForCheck(\DateTimeImmutable $now, int $limit): array
    {
        $due = [];
        foreach ($this->attempts as $id => $a) {
            if (in_array($a['state'], ['pending', 'unknown'], true) && $a['next'] !== null && $a['next'] <= $now) {
                $due[] = new AttemptToVerify(
                    $id,
                    $a['attempt']->accountId,
                    $a['attempt']->reference,
                    $a['attempt']->gateway,
                    $a['attempt']->amount,
                    PaymentAttemptState::from($a['state']),
                    $a['checks'],
                    $a['attempt']->createdAt,
                );
            }
        }

        return array_slice($due, 0, $limit);
    }

    public function rescheduled(string $attemptId, int $checksSeen, \DateTimeImmutable $nextCheckAt, bool $unknown, \DateTimeImmutable $now): bool
    {
        $a = $this->attempts[$attemptId] ?? null;
        if ($a === null || $a['checks'] !== $checksSeen || !in_array($a['state'], ['pending', 'unknown'], true)) {
            return false;
        }
        $this->attempts[$attemptId]['checks']++;
        $this->attempts[$attemptId]['next'] = $nextCheckAt;
        if ($unknown) {
            $this->attempts[$attemptId]['state'] = 'unknown';
        }

        return true;
    }

    public function checkNow(string $accountId, string $attemptId, \DateTimeImmutable $now): bool
    {
        $a = $this->attempts[$attemptId] ?? null;
        if ($a === null || $a['attempt']->accountId !== $accountId || !in_array($a['state'], ['pending', 'unknown'], true) || ($a['next'] !== null && $a['next'] <= $now)) {
            return false;
        }
        $this->attempts[$attemptId]['next'] = $now;

        return true;
    }

    public function close(string $attemptId, int $checksSeen, PaymentAttemptState $final, \DateTimeImmutable $now): bool
    {
        $a = $this->attempts[$attemptId] ?? null;
        if ($a === null || $a['checks'] !== $checksSeen || !in_array($a['state'], ['pending', 'unknown'], true)) {
            return false;
        }
        $this->attempts[$attemptId]['checks']++;
        $this->attempts[$attemptId]['next'] = null;
        $this->attempts[$attemptId]['state'] = $final->value;

        return true;
    }

    public function recordSuccess(string $attemptId, GatewayVerification $verification, \DateTimeImmutable $now): ?string
    {
        if ($this->failNextRecord) {
            $this->failNextRecord = false;
            throw new \RuntimeException('Simulated transaction failure.');
        }
        if (!$this->settle($attemptId, 'succeeded', $verification)) {
            return null;
        }
        $paid = $verification->amount ?? throw new \InvalidArgumentException('A success names its amount.');
        $invoiceId = $this->attempts[$attemptId]['attempt']->invoiceId;
        $applied = false;
        foreach ($this->invoices as $order => $row) {
            $invoice = $row['invoice'];
            if ($invoice->invoiceId === $invoiceId && $invoice->status === InvoiceStatus::Unpaid) {
                $this->invoices[$order]['invoice'] = new PayableInvoice($invoice->invoiceId, $invoice->number, InvoiceStatus::Paid, $invoice->balance, $invoice->expiresAt);
                $applied = true;
            }
        }
        $this->postings[] = ['kind' => $applied ? 'payment' : 'overpayment', 'payment' => $attemptId, 'minor' => $paid->minorUnits, 'currency' => $paid->currency->value];
        $fee = $verification->fee;
        if ($fee !== null && $fee->minorUnits > 0 && $fee->currency === $paid->currency) {
            $this->postings[] = ['kind' => 'gateway_fee', 'payment' => $attemptId, 'minor' => $fee->minorUnits, 'currency' => $fee->currency->value];
        }

        return $applied ? 'applied' : 'credited';
    }

    public function recordMismatch(string $attemptId, GatewayVerification $verification, \DateTimeImmutable $now): bool
    {
        if (!$this->settle($attemptId, 'amount_mismatch', $verification)) {
            return false;
        }
        if ($verification->amount !== null) {
            $this->postings[] = ['kind' => 'unallocated', 'payment' => $attemptId, 'minor' => $verification->amount->minorUnits, 'currency' => $verification->amount->currency->value];
        }

        return true;
    }

    private function settle(string $attemptId, string $state, GatewayVerification $verification): bool
    {
        if (!in_array($this->attempts[$attemptId]['state'] ?? null, ['initiated', 'pending', 'unknown'], true)) {
            return false;
        }
        $this->attempts[$attemptId]['state'] = $state;
        $this->attempts[$attemptId]['next'] = null;
        $this->attempts[$attemptId]['paid'] = $verification;

        return true;
    }
}
