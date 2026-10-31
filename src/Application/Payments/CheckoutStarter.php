<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\PaymentAttemptStore;
use PaxofiCloud\Application\Contracts\PaymentGateways;

/**
 * Payments worker step (THREAT-MODEL-PAYMENTS.md PY-6): claims initiated
 * attempts and asks the gateway for a hosted checkout. The callback URL is
 * fixed here from configuration (`APP_URL/billing/payments/{id}`, PT-20);
 * the amount, currency and reference come from the attempt row. A checkout
 * URL is stored only after the adapter checked its host (PT-11). Any
 * failure moves the attempt to `failed` with a fixed code and leaves the
 * invoice untouched, so the customer can try again or use the other
 * gateway (PT-21). An attempt nobody picked up within ten minutes is failed
 * without calling the gateway (the customer has long stopped waiting), and so
 * is one whose earlier claim never finished (no second initialise).
 */
final readonly class CheckoutStarter
{
    public const int STALE_AFTER_SECONDS = 600;
    public const int MAX_PER_RUN = 20;

    public function __construct(
        private PaymentAttemptStore $attempts,
        private PaymentGateways $gateways,
        private string $appUrl,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /** @return int attempts handled */
    public function runOnce(): int
    {
        $handled = 0;
        while ($handled < self::MAX_PER_RUN && ($attempt = $this->attempts->claimNext($this->clock->now())) !== null) {
            $handled++;
            $this->start($attempt);
        }

        return $handled;
    }

    private function start(AttemptToStart $attempt): void
    {
        if ($attempt->reclaimed) {
            // Never a second initialise for one reference: the first may have reached the gateway.
            $this->fail($attempt, 'interrupted');

            return;
        }
        $now = $this->clock->now();
        if ($now->getTimestamp() - $attempt->createdAt->getTimestamp() > self::STALE_AFTER_SECONDS) {
            $this->fail($attempt, 'stale');

            return;
        }
        try {
            $request = new CheckoutRequest(
                $attempt->reference,
                $attempt->amount,
                $attempt->payerEmail,
                rtrim($this->appUrl, '/') . '/billing/payments/' . $attempt->id,
                $attempt->invoiceNumber,
            );
        } catch (\InvalidArgumentException) {
            $this->fail($attempt, 'request_invalid');

            return;
        }
        try {
            $checkout = $this->gateways->for($attempt->gateway)->initialise($request);
        } catch (GatewayUnavailable $e) {
            $this->fail($attempt, $e->reason);

            return;
        } catch (\Throwable $e) {
            // Never the message: it may quote a gateway body (PY-19).
            $this->logger->error('payments.initialise_error', ['payment' => $attempt->id, 'error' => $e::class]);
            $this->fail($attempt, 'internal_error');

            return;
        }
        if ($checkout->reference->value !== $attempt->reference->value) {
            $this->fail($attempt, 'malformed');

            return;
        }
        if ($this->attempts->markCheckoutReady($attempt->id, $checkout->url, $this->clock->now())) {
            $this->audit->record(AuditEventType::PAYMENT_CHECKOUT_READY, AuditEvent::SUCCESS, ClientContext::ip('0.0.0.0'), accountId: $attempt->accountId, details: [
                'payment' => $attempt->id,
                'gateway' => $attempt->gateway->value,
            ]);
        }
    }

    private function fail(AttemptToStart $attempt, string $reason): void
    {
        if ($this->attempts->markFailed($attempt->id, $reason, $this->clock->now())) {
            $this->logger->warning('payments.initialise_failed', ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value, 'reason' => $reason]);
            $this->audit->record(AuditEventType::PAYMENT_FAILED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), accountId: $attempt->accountId, details: [
                'payment' => $attempt->id,
                'gateway' => $attempt->gateway->value,
                'reason' => $reason,
            ]);
        }
    }
}
