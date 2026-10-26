<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Payments\Flutterwave;

use PaxofiCloud\Application\Contracts\PaymentGateway;
use PaxofiCloud\Application\Payments\CheckoutRequest;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\HostedCheckout;
use PaxofiCloud\Application\Payments\PaymentOutcome;
use PaxofiCloud\Application\Payments\WebhookHint;
use PaxofiCloud\Application\Payments\WebhookRejected;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\DecimalAmount;
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
 * Flutterwave v3 adapter (THREAT-MODEL-PAYMENTS.md §2, PY-1, PY-4). Our own
 * client over the allow-listed ProviderHttpClient; no SDK.
 *
 * Flutterwave amounts are major units; they are converted with string
 * arithmetic only (DecimalAmount). Its webhooks carry no body signature: the
 * `verif-hash` header must equal the secret hash staff set in the dashboard,
 * compared in constant time. That makes verify-by-reference the only proof
 * of payment (PY-7), as for Paystack.
 */
final readonly class FlutterwaveClient implements PaymentGateway
{
    public const string BASE_URL = 'https://api.flutterwave.com/v3';
    /** R-2: the test-mode checkout host is confirmed on staging; an unknown host fails closed. */
    public const array CHECKOUT_HOSTS = ['checkout.flutterwave.com', 'checkout-testing.flutterwave.com'];

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
        return Gateway::Flutterwave;
    }

    public function initialise(CheckoutRequest $request): HostedCheckout
    {
        $response = $this->send(ProviderRequest::post('/payments', [
            'tx_ref' => $request->reference->value,
            'amount' => DecimalAmount::toText($request->amount),
            'currency' => $request->amount->currency->value,
            'redirect_url' => $request->callbackUrl,
            'customer' => ['email' => $request->email],
            'customizations' => ['title' => 'PaxofiCloud', 'description' => 'Invoice ' . $request->invoiceNumber],
            'meta' => ['invoice' => $request->invoiceNumber],
        ]));
        GatewayResponses::failIfUnusable($response->status);
        $document = GatewayResponses::document($response->body);
        if ($response->status >= 300 || ($document['status'] ?? null) !== 'success') {
            throw new GatewayUnavailable('rejected');
        }
        $data = $document['data'] ?? null;

        return new HostedCheckout($request->reference, GatewayResponses::checkoutUrl(is_array($data) ? ($data['link'] ?? null) : null, self::CHECKOUT_HOSTS));
    }

    public function verify(PaymentReference $reference): GatewayVerification
    {
        $response = $this->send(ProviderRequest::get('/transactions/verify_by_reference', ['tx_ref' => $reference->value]));
        GatewayResponses::failIfUnusable($response->status);
        if ($response->status === 400 || $response->status === 404) {
            return new GatewayVerification($reference, PaymentOutcome::NotFound);
        }
        if ($response->status >= 300) {
            throw new GatewayUnavailable('provider_error');
        }
        $document = GatewayResponses::document($response->body);
        $data = $document['data'] ?? null;
        if (($document['status'] ?? null) !== 'success' || !is_array($data) || ($data['tx_ref'] ?? null) !== $reference->value) {
            throw new GatewayUnavailable('malformed');
        }

        $status = $data['status'] ?? null;
        $outcome = match ($status) {
            'successful' => PaymentOutcome::Succeeded,
            'failed', 'cancelled' => PaymentOutcome::Failed,
            default => PaymentOutcome::Pending,
        };
        $currency = is_string($data['currency'] ?? null) ? Currency::tryFrom($data['currency']) : null;
        $amount = $currency === null ? null : self::amount($data['amount'] ?? null, $currency);
        $id = GatewayResponses::transactionId($data['id'] ?? null);
        if ($outcome === PaymentOutcome::Succeeded && ($amount === null || $id === null)) {
            throw new GatewayUnavailable('malformed');
        }

        return new GatewayVerification(
            $reference,
            $outcome,
            $amount,
            $id,
            GatewayResponses::timestamp($data['created_at'] ?? null),
            $currency === null ? null : self::amount($data['app_fee'] ?? null, $currency),
        );
    }

    public function webhookHint(string $rawBody, array $headers): ?WebhookHint
    {
        if (strlen($rawBody) > GatewayResponses::MAX_BODY_BYTES) {
            throw new WebhookRejected('too_large');
        }
        $hash = $headers['verif-hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new WebhookRejected('signature_missing');
        }
        if (!hash_equals($this->credential()->webhookHash(), $hash)) {
            throw new WebhookRejected('signature_invalid');
        }
        try {
            $document = GatewayResponses::document($rawBody);
        } catch (GatewayUnavailable) {
            throw new WebhookRejected('malformed');
        }
        $event = $document['event'] ?? null;
        $data = $document['data'] ?? null;
        if ($event !== 'charge.completed' || !is_array($data) || !is_string($data['tx_ref'] ?? null)) {
            return null;
        }
        $reference = PaymentReference::tryFrom($data['tx_ref']);

        return $reference === null ? null : new WebhookHint(Gateway::Flutterwave, $reference, $event);
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

        return GatewayCredential::from(Gateway::Flutterwave, $stored, $this->mode);
    }

    private static function amount(mixed $value, Currency $currency): ?Money
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return DecimalAmount::toMinor($value, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
