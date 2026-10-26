<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Payments\CheckoutRequest;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\HostedCheckout;
use PaxofiCloud\Application\Payments\WebhookHint;
use PaxofiCloud\Application\Payments\WebhookRejected;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * One payment gateway (PAY-001, THREAT-MODEL-PAYMENTS.md PY-1). Billing code
 * talks only to this port; adapters are our own, with no gateway SDK.
 *
 * Only verify() decides whether money arrived (PY-7): a webhook is a hint
 * that names a reference to verify, nothing more.
 */
interface PaymentGateway
{
    public function gateway(): Gateway;

    /**
     * Starts a hosted checkout for one attempt. Card details are entered on
     * the gateway's page, never ours (PCI DSS SAQ-A).
     *
     * @throws GatewayUnavailable
     */
    public function initialise(CheckoutRequest $request): HostedCheckout;

    /** @throws GatewayUnavailable */
    public function verify(PaymentReference $reference): GatewayVerification;

    /**
     * Checks a webhook's signature over the raw body before reading it. Null
     * when the event is genuine but not one we act on.
     *
     * @param array<string, string> $headers lower-case names
     * @throws WebhookRejected
     * @throws GatewayUnavailable when the credential cannot be loaded
     */
    public function webhookHint(string $rawBody, array $headers): ?WebhookHint;
}
