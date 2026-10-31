<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\GatewayEventStore;
use PaxofiCloud\Application\Contracts\PaymentGateways;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Domain\Payments\Gateway;

/**
 * Gateway webhooks, served only by payments-web (THREAT-MODEL-PAYMENTS.md
 * PY-7, PY-8; PAY-005). The signature is checked over the raw body before
 * anything is read. A genuine event is recorded once and only brings its
 * attempt's next verify check forward; it never marks anything paid, so a
 * forged or replayed event can at most cause one extra gateway check.
 *
 * Answers: 200 recorded, duplicate or not an event we act on; 401 bad or
 * missing signature; 400 unreadable; 503 when the gateway key cannot be
 * loaded, so the gateway retries later. Rejections are audited at most once
 * a minute per gateway and reason, so a flood cannot fill the audit log.
 */
final readonly class WebhookReceiver
{
    public const int REJECTION_AUDITS_PER_MINUTE = 1;

    public function __construct(
        private PaymentGateways $gateways,
        private GatewayEventStore $events,
        private RateLimiter $limiter,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /** @param array<string, string> $headers lower-case names */
    public function receive(Gateway $gateway, string $rawBody, array $headers, ClientContext $client): int
    {
        try {
            $hint = $this->gateways->for($gateway)->webhookHint($rawBody, $headers);
        } catch (WebhookRejected $e) {
            $this->rejected($gateway, $e->reason, $client);

            return in_array($e->reason, ['signature_missing', 'signature_invalid'], true) ? 401 : 400;
        } catch (GatewayUnavailable $e) {
            $this->logger->error('payments.webhook_unavailable', ['gateway' => $gateway->value, 'reason' => $e->reason]);

            return 503;
        }
        if ($hint === null) {
            // Genuine, but not an event we act on (for example transfer or refund events today).
            return 200;
        }
        if ($hint->gateway !== $gateway) {
            $this->rejected($gateway, 'malformed', $client);

            return 400;
        }
        if ($this->events->record($hint, hash('sha256', $rawBody), $this->clock->now())) {
            $this->logger->info('payments.webhook_recorded', ['gateway' => $gateway->value, 'event' => $hint->event]);
        }

        return 200;
    }

    private function rejected(Gateway $gateway, string $reason, ClientContext $client): void
    {
        $this->logger->warning('payments.webhook_rejected', ['gateway' => $gateway->value, 'reason' => $reason]);
        if ($this->limiter->hit('payments:webhook_rejected:' . $gateway->value . ':' . $reason, self::REJECTION_AUDITS_PER_MINUTE, 60) > 0) {
            return;
        }
        $this->audit->record(AuditEventType::PAYMENT_WEBHOOK_REJECTED, AuditEvent::FAILURE, $client, details: [
            'gateway' => $gateway->value,
            'reason' => $reason,
        ]);
    }
}
