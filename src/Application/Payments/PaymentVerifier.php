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
use PaxofiCloud\Domain\Payments\PaymentAttemptState;

/**
 * Payments worker step (THREAT-MODEL-PAYMENTS.md PY-8, PY-9, PY-11): asks
 * the gateway what happened to each open attempt, on a fixed schedule after
 * the attempt was created (2 min, 10 min, 1 h, 6 h, 24 h). Only this answer
 * moves money; a webhook (next step) will only bring a check forward.
 *
 * Paid only when the gateway says success for our reference AND the amount
 * and currency equal the attempt's to the minor unit; a success with any
 * difference is amount_mismatch and goes to unallocated receipts. Any other
 * answer, including failed (the customer may still retry on the same
 * checkout page), waits for the next check; after the last one the attempt
 * is closed as failed or abandoned. A gateway that cannot answer makes the
 * attempt unknown and it is checked again on the same schedule (bounded).
 */
final readonly class PaymentVerifier
{
    /** Seconds after the attempt was created at which it is checked (PY-11). */
    public const array SCHEDULE_SECONDS = [120, 600, 3600, 21_600, 86_400];
    public const int MAX_PER_RUN = 20;

    public function __construct(
        private PaymentAttemptStore $attempts,
        private PaymentGateways $gateways,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /** @return int attempts checked */
    public function runOnce(): int
    {
        $due = $this->attempts->dueForCheck($this->clock->now(), self::MAX_PER_RUN);
        foreach ($due as $attempt) {
            $this->check($attempt);
        }

        return count($due);
    }

    private function check(AttemptToVerify $attempt): void
    {
        try {
            $verification = $this->gateways->for($attempt->gateway)->verify($attempt->reference);
        } catch (GatewayUnavailable $e) {
            $this->logger->warning('payments.verify_unavailable', ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value, 'reason' => $e->reason]);
            $this->next($attempt, null, unknown: true);

            return;
        } catch (\Throwable $e) {
            // Never the message: it may quote a gateway body (PY-19).
            $this->logger->error('payments.verify_error', ['payment' => $attempt->id, 'error' => $e::class]);
            $this->next($attempt, null, unknown: true);

            return;
        }
        if ($verification->reference->value !== $attempt->reference->value) {
            $this->logger->warning('payments.verify_unavailable', ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value, 'reason' => 'malformed']);
            $this->next($attempt, null, unknown: true);

            return;
        }
        if ($verification->outcome !== PaymentOutcome::Succeeded) {
            $this->next($attempt, $verification->outcome, unknown: false);

            return;
        }

        try {
            $this->settle($attempt, $verification);
        } catch (\Throwable $e) {
            // The transaction rolled back; check again on the schedule rather than every loop.
            $this->logger->error('payments.record_error', ['payment' => $attempt->id, 'error' => $e::class]);
            $this->next($attempt, null, unknown: true);
        }
    }

    private function settle(AttemptToVerify $attempt, GatewayVerification $verification): void
    {
        $now = $this->clock->now();
        $paid = $verification->amount;
        if ($paid === null || $paid->currency !== $attempt->amount->currency || $paid->minorUnits !== $attempt->amount->minorUnits) {
            if ($this->attempts->recordMismatch($attempt->id, $verification, $now)) {
                $this->logger->error('payments.amount_mismatch', ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value]);
                $this->audit->record(AuditEventType::PAYMENT_AMOUNT_MISMATCH, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), accountId: $attempt->accountId, details: [
                    'payment' => $attempt->id,
                    'gateway' => $attempt->gateway->value,
                    'expected_minor' => $attempt->amount->minorUnits,
                    'paid_minor' => $paid === null ? 0 : $paid->minorUnits,
                    'paid_currency' => $paid === null ? 'none' : strtolower($paid->currency->value),
                ]);
            }

            return;
        }

        $result = $this->attempts->recordSuccess($attempt->id, $verification, $now);
        if ($result === null) {
            return;
        }
        $this->audit->record(
            $result === 'applied' ? AuditEventType::PAYMENT_SUCCEEDED : AuditEventType::PAYMENT_CREDITED,
            AuditEvent::SUCCESS,
            ClientContext::ip('0.0.0.0'),
            accountId: $attempt->accountId,
            details: [
                'payment' => $attempt->id,
                'gateway' => $attempt->gateway->value,
                'amount_minor' => $paid->minorUnits,
                'currency' => strtolower($paid->currency->value),
            ],
        );
        if ($result === 'credited') {
            // PY-12: real money for an invoice that is no longer unpaid; Billing follows up.
            $this->logger->warning('payments.credited_to_account', ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value]);
        }
    }

    private function next(AttemptToVerify $attempt, ?PaymentOutcome $outcome, bool $unknown): void
    {
        $done = $attempt->checks + 1;
        $now = $this->clock->now();
        if ($done >= count(self::SCHEDULE_SECONDS)) {
            $final = $outcome === PaymentOutcome::Failed ? PaymentAttemptState::Failed : PaymentAttemptState::Abandoned;
            if ($this->attempts->close($attempt->id, $attempt->checks, $final, $now)) {
                $this->audit->record(
                    $final === PaymentAttemptState::Failed ? AuditEventType::PAYMENT_FAILED : AuditEventType::PAYMENT_ABANDONED,
                    AuditEvent::FAILURE,
                    ClientContext::ip('0.0.0.0'),
                    accountId: $attempt->accountId,
                    details: ['payment' => $attempt->id, 'gateway' => $attempt->gateway->value, 'reason' => $outcome === null ? 'unverified' : $outcome->value],
                );
            }

            return;
        }
        $nextAt = $attempt->createdAt->modify('+' . self::SCHEDULE_SECONDS[$done] . ' seconds');
        $this->attempts->rescheduled($attempt->id, $attempt->checks, $nextAt, $unknown, $now);
    }
}
