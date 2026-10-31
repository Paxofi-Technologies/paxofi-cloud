<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\BillingStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\PaymentAttemptStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * Starting a payment for an invoice (THREAT-MODEL-PAYMENTS.md PY-5, PY-6,
 * PY-17; SRS PAY-002, PAY-004). The web tier only records the attempt; the
 * payments worker, which alone holds gateway keys (PY-3), starts the hosted
 * checkout. The amount is the invoice balance at this moment, never a
 * number from the browser.
 *
 * @phpstan-import-type AttemptRow from PaymentAttemptStore
 */
final readonly class InvoicePayments
{
    public const int ATTEMPTS_PER_HOUR = 10;
    public const int MAX_OPEN_ATTEMPTS = 5;
    public const int CHECK_EVERY_SECONDS = 30;
    private const string KEY = '/^[A-Za-z0-9_-]{16,64}$/';

    /** @param 'dev'|'staging'|'prod' $environment */
    public function __construct(
        private PaymentAttemptStore $attempts,
        private BillingStore $billing,
        private RateLimiter $limiter,
        private AuditTrail $audit,
        private Clock $clock,
        private string $environment,
    ) {
    }

    /**
     * What the pay screen may offer for one of the account's orders (PY-17).
     *
     * @return array{invoice_number: string, status: string, amount: int, currency: string, payable: bool, gateways: list<string>}
     * @throws PaymentRefused
     */
    public function options(string $accountId, string $orderId): array
    {
        $invoice = $this->attempts->payableInvoice($accountId, $orderId) ?? throw new PaymentRefused(PaymentRefused::INVOICE_NOT_FOUND);
        $payable = $invoice->status === InvoiceStatus::Unpaid && $invoice->expiresAt > $this->clock->now();

        return [
            'invoice_number' => $invoice->number,
            'status' => $invoice->status->value,
            'amount' => $invoice->balance->minorUnits,
            'currency' => $invoice->balance->currency->value,
            'payable' => $payable,
            'gateways' => $payable ? array_map(static fn (Gateway $g): string => $g->value, $this->offered($invoice)) : [],
        ];
    }

    /**
     * Records an `initiated` attempt for the payments worker to start. The
     * same Idempotency-Key for the same order and gateway answers with the
     * first attempt; with anything else it is refused.
     *
     * @return array{payment_id: string, replayed: bool}
     * @throws PaymentRefused
     */
    public function start(string $identityId, string $accountId, string $orderId, string $gatewayName, string $idempotencyKey, ClientContext $client): array
    {
        if (preg_match(self::KEY, $idempotencyKey) !== 1) {
            throw new PaymentRefused(PaymentRefused::KEY_INVALID);
        }
        if (!$this->billing->isOwner($identityId, $accountId)) {
            throw new PaymentRefused(PaymentRefused::NOT_OWNER);
        }
        $requestHash = hash('sha256', 'pay|' . $orderId . '|' . $gatewayName);
        $previous = $this->attempts->findByIdempotencyKey($accountId, $idempotencyKey);
        if ($previous !== null) {
            return self::replay($previous, $requestHash);
        }
        $retry = $this->limiter->hit('payments:start:' . hash('sha256', $accountId), self::ATTEMPTS_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new PaymentRefused(PaymentRefused::TOO_MANY, $retry);
        }

        $invoice = $this->attempts->payableInvoice($accountId, $orderId) ?? throw new PaymentRefused(PaymentRefused::INVOICE_NOT_FOUND);
        $now = $this->clock->now();
        if ($invoice->status !== InvoiceStatus::Unpaid) {
            throw new PaymentRefused(PaymentRefused::NOT_PAYABLE);
        }
        if ($invoice->expiresAt <= $now) {
            throw new PaymentRefused(PaymentRefused::EXPIRED);
        }
        $gateway = Gateway::tryFrom($gatewayName);
        if ($gateway === null || !in_array($gateway, $this->offered($invoice), true)) {
            throw new PaymentRefused(PaymentRefused::GATEWAY_UNAVAILABLE);
        }
        $minimum = $gateway->minimum($invoice->balance->currency);
        if ($minimum !== null && $invoice->balance->compare($minimum) < 0) {
            throw new PaymentRefused(PaymentRefused::BELOW_MINIMUM);
        }
        if ($this->attempts->openAttempts($invoice->invoiceId) >= self::MAX_OPEN_ATTEMPTS) {
            throw new PaymentRefused(PaymentRefused::TOO_MANY_OPEN);
        }

        $attempt = new NewPaymentAttempt(
            bin2hex(random_bytes(16)),
            PaymentReference::generate($now),
            $accountId,
            $invoice->invoiceId,
            $identityId,
            $gateway,
            $invoice->balance,
            $idempotencyKey,
            $requestHash,
            $now,
        );
        try {
            $this->attempts->create($attempt);
        } catch (DuplicateIdempotencyKey) {
            // A concurrent request with the same key won the race: answer with its attempt.
            $previous = $this->attempts->findByIdempotencyKey($accountId, $idempotencyKey) ?? throw new PaymentRefused(PaymentRefused::KEY_REUSED);

            return self::replay($previous, $requestHash);
        }
        $this->audit->record(AuditEventType::PAYMENT_STARTED, AuditEvent::SUCCESS, $client, $identityId, $accountId, [
            'payment' => $attempt->id,
            'invoice' => $invoice->invoiceId,
            'gateway' => $gateway->value,
            'amount_minor' => $attempt->amount->minorUnits,
            'currency' => strtolower($attempt->amount->currency->value),
        ]);

        return ['payment_id' => $attempt->id, 'replayed' => false];
    }

    /**
     * One of the account's attempts, as the return page and the pay button poll it (PY-6, PY-10).
     *
     * @return AttemptRow
     * @throws PaymentRefused
     */
    public function status(string $accountId, string $attemptId): array
    {
        return $this->attempts->find($accountId, $attemptId) ?? throw new PaymentRefused(PaymentRefused::ATTEMPT_NOT_FOUND);
    }

    /**
     * The customer is back from the gateway (PY-10): ask the gateway now
     * instead of waiting for the schedule, at most once per attempt every 30
     * seconds. The gateway's query parameters on the return URL are never
     * read; the answer comes from status() once the worker has checked.
     *
     * @return AttemptRow
     * @throws PaymentRefused
     */
    public function checkNow(string $accountId, string $attemptId): array
    {
        $row = $this->status($accountId, $attemptId);
        if (in_array($row['state'], ['pending', 'unknown'], true)
            && $this->limiter->hit('payments:check:' . $attemptId, 1, self::CHECK_EVERY_SECONDS) === 0) {
            $this->attempts->checkNow($accountId, $attemptId, $this->clock->now());
        }

        return $row;
    }

    /** @return list<Gateway> */
    private function offered(PayableInvoice $invoice): array
    {
        $currency = $invoice->balance->currency;

        return array_values(array_filter(
            $this->attempts->connectedGateways($this->environment),
            static fn (Gateway $g): bool => $g->collects($currency),
        ));
    }

    /**
     * @param array{id: string, request_hash: string} $previous
     * @return array{payment_id: string, replayed: bool}
     * @throws PaymentRefused
     */
    private static function replay(array $previous, string $requestHash): array
    {
        if (!hash_equals($previous['request_hash'], $requestHash)) {
            throw new PaymentRefused(PaymentRefused::KEY_REUSED);
        }

        return ['payment_id' => $previous['id'], 'replayed' => true];
    }
}
