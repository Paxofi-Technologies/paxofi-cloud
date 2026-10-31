<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Payments;

use PaxofiCloud\Application\Contracts\PaymentGateway;
use PaxofiCloud\Application\Contracts\PaymentGateways;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PaxofiCloud\Integration\Payments\Flutterwave\FlutterwaveClient;
use PaxofiCloud\Integration\Payments\Paystack\PaystackClient;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Builds the gateway adapters inside the payments worker (PY-1, PY-3, PY-4).
 * The credential source reads `payment_gateway_credentials` under the
 * payments key ring; each adapter talks only to its own allow-listed host
 * and checks the key mode before every call.
 */
final readonly class ConfiguredPaymentGateways implements PaymentGateways
{
    /**
     * @param 'dev'|'staging'|'prod' $environment
     * @param (\Closure(Gateway): ProviderTransport)|null $transport for tests; production uses ProviderHttpClient
     */
    public function __construct(
        private ProviderCredentialSource $credentials,
        private string $environment,
        private GatewayMode $mode,
        private ?\Closure $transport = null,
    ) {
    }

    public function for(Gateway $gateway): PaymentGateway
    {
        $transport = $this->transport !== null ? ($this->transport)($gateway) : self::http($gateway);

        return match ($gateway) {
            Gateway::Paystack => new PaystackClient($transport, $this->credentials, ProviderConnection::Paystack->credentialId($this->environment), $this->mode),
            Gateway::Flutterwave => new FlutterwaveClient($transport, $this->credentials, ProviderConnection::Flutterwave->credentialId($this->environment), $this->mode),
        };
    }

    private static function http(Gateway $gateway): ProviderTransport
    {
        $endpoint = match ($gateway) {
            Gateway::Paystack => PaystackClient::endpoint(),
            Gateway::Flutterwave => FlutterwaveClient::endpoint(),
        };

        return new ProviderHttpClient([$endpoint], connectTimeoutMs: 3_000, totalTimeoutMs: 15_000, maxResponseBytes: 65_536);
    }
}
