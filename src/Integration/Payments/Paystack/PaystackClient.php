<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Payments\Paystack;

use PaxofiCloud\Application\Contracts\PaymentGateway;
use PaxofiCloud\Application\Payments\CheckoutRequest;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\HostedCheckout;
use PaxofiCloud\Application\Payments\PaymentOutcome;
use PaxofiCloud\Application\Payments\WebhookHint;
use PaxofiCloud\Application\Payments\WebhookRejected;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Integration\Payments\GatewayCredential;
use PaxofiCloud\Integration\Payments\GatewayResponses;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Paystack adapter (THREAT-MODEL-PAYMENTS.md §2, PY-1, PY-4). Our own client
 * over the allow-listed ProviderHttpClient; no SDK.
 *
 * Paystack answers HTTP 200 for a failed charge, so only `data.status` decides
 * the outcome; amounts are integer subunits (kobo/cents). The live/test
 * `data.domain` in a verify answer must match this environment's mode, a
 * second check after the key-prefix guard. Webhooks carry
 * `x-paystack-signature` = HMAC-SHA512 of the raw body with the secret key.
 */
final readonly class PaystackClient implements PaymentGateway
{
    public const string BASE_URL = 'https://api.paystack.co';
    public const array CHECKOUT_HOSTS = ['checkout.paystack.com'];

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredentialSource $credentials,
        private string $credentialId,
        private GatewayMode $mode,
    ) {
    }

    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL);
    }

    public function gateway(): Gateway
    {
        return Gateway::Paystack;
    }

    public function initialise(CheckoutRequest $request): HostedCheckout
    {
        $minimum = Gateway::Paystack->minimum($request->amount->currency);
        if ($minimum !== null && $request->amount->compare($minimum) < 0) {
            throw new GatewayUnavailable('below_minimum');
        }
        $response = $this->send(ProviderRequest::post('/transaction/initialize', [
            'email' => $request->email,
            'amount' => (string) $request->amount->minorUnits,
            'currency' => $request->amount->currency->value,
            'reference' => $request->reference->value,
            'callback_url' => $request->callbackUrl,
            'metadata' => json_encode(['invoice' => $request->invoiceNumber], JSON_THROW_ON_ERROR),
        ]));
        GatewayResponses::failIfUnusable($response->status);
        $document = GatewayResponses::document($response->body);
        $data = $document['data'] ?? null;
        if ($response->status >= 300 || ($document['status'] ?? null) !== true) {
            throw new GatewayUnavailable('rejected');
        }
        if (!is_array($data) || ($data['reference'] ?? null) !== $request->reference->value) {
            throw new GatewayUnavailable('malformed');
        }

        return new HostedCheckout($request->reference, GatewayResponses::checkoutUrl($data['authorization_url'] ?? null, self::CHECKOUT_HOSTS));
    }

    public function verify(PaymentReference $reference): GatewayVerification
    {
        $response = $this->send(ProviderRequest::get('/transaction/verify/' . $reference->value));
        GatewayResponses::failIfUnusable($response->status);
        if ($response->status === 400 || $response->status === 404) {
            return new GatewayVerification($reference, PaymentOutcome::NotFound);
        }
        if ($response->status >= 300) {
            throw new GatewayUnavailable('provider_error');
        }
        $document = GatewayResponses::document($response->body);
        $data = $document['data'] ?? null;
        if (($document['status'] ?? null) !== true || !is_array($data) || ($data['reference'] ?? null) !== $reference->value) {
            throw new GatewayUnavailable('malformed');
        }
        if (($data['domain'] ?? null) !== $this->mode->value) {
            throw new GatewayUnavailable('key_mode_mismatch');
        }

        $status = $data['status'] ?? null;
        $outcome = match (true) {
            $status === 'success' => PaymentOutcome::Succeeded,
            $status === 'failed', $status === 'reversed' => PaymentOutcome::Failed,
            $status === 'abandoned' => PaymentOutcome::Abandoned,
            default => PaymentOutcome::Pending,
        };
        $currency = is_string($data['currency'] ?? null) ? Currency::tryFrom($data['currency']) : null;
        $amount = self::minor($data['amount'] ?? null);
        $id = GatewayResponses::transactionId($data['id'] ?? null);
        if ($outcome === PaymentOutcome::Succeeded && ($currency === null || $amount === null || $id === null)) {
            throw new GatewayUnavailable('malformed');
        }
        $fee = self::minor($data['fees'] ?? null);

        return new GatewayVerification(
            $reference,
            $outcome,
            $currency !== null && $amount !== null ? new Money($amount, $currency) : null,
            $id,
            GatewayResponses::timestamp($data['paid_at'] ?? null),
            $currency !== null && $fee !== null ? new Money($fee, $currency) : null,
        );
    }

    public function webhookHint(string $rawBody, array $headers): ?WebhookHint
    {
        if (strlen($rawBody) > GatewayResponses::MAX_BODY_BYTES) {
            throw new WebhookRejected('too_large');
        }
        $signature = $headers['x-paystack-signature'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new WebhookRejected('signature_missing');
        }
        $credential = $this->credential();
        if (!hash_equals(hash_hmac('sha512', $rawBody, $credential->signingKey()), strtolower($signature))) {
            throw new WebhookRejected('signature_invalid');
        }
        try {
            $document = GatewayResponses::document($rawBody);
        } catch (GatewayUnavailable) {
            throw new WebhookRejected('malformed');
        }
        $event = $document['event'] ?? null;
        $data = $document['data'] ?? null;
        if ($event !== 'charge.success' || !is_array($data) || !is_string($data['reference'] ?? null)) {
            return null;
        }
        $reference = PaymentReference::tryFrom($data['reference']);

        return $reference === null ? null : new WebhookHint(Gateway::Paystack, $reference, $event);
    }

    private function send(ProviderRequest $request): ProviderResponse
    {
        $credential = $this->credential();
        try {
            return $this->transport->send(self::BASE_URL, $request, $credential->bearer());
        } catch (TransportFailure) {
            throw new GatewayUnavailable('transport');
        }
    }

    private function credential(): GatewayCredential
    {
        try {
            $stored = $this->credentials->load($this->credentialId);
        } catch (\RuntimeException) {
            throw new GatewayUnavailable('credential_missing');
        }

        return GatewayCredential::from(Gateway::Paystack, $stored, $this->mode);
    }

    /** Paystack subunits: a non-negative integer (ExactJson keeps it as text). */
    private static function minor(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,14})$/D', $value) === 1 ? (int) $value : null;
    }
}
