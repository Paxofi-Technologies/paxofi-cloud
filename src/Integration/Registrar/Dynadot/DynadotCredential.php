<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * The stored Dynadot secret is the JSON {api_key, api_secret} written by the
 * provider worker (DY-2). The key travels only in the Authorization header;
 * the secret never travels at all: it only signs requests (DY-3).
 */
final class DynadotCredential
{
    private const string FORMAT = '/^[\x21-\x7E]{8,256}$/';

    private function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {
    }

    /** @throws RegistrarUnavailable credential_invalid */
    public static function from(ProviderCredential $stored): self
    {
        try {
            $fields = json_decode($stored->reveal(), true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RegistrarUnavailable('credential_invalid');
        }
        if (!is_array($fields) || array_keys($fields) !== ['api_key', 'api_secret']) {
            throw new RegistrarUnavailable('credential_invalid');
        }
        ['api_key' => $apiKey, 'api_secret' => $apiSecret] = $fields;
        if (!is_string($apiKey) || preg_match(self::FORMAT, $apiKey) !== 1 || !is_string($apiSecret) || preg_match(self::FORMAT, $apiSecret) !== 1) {
            throw new RegistrarUnavailable('credential_invalid');
        }

        return new self($apiKey, $apiSecret);
    }

    /** The bearer token for the transport, under the stored credential's ID. */
    public function bearer(string $credentialId): ProviderCredential
    {
        return new ProviderCredential($credentialId, $this->apiKey);
    }

    /** DY-3: the X-Signature value for one request. */
    public function sign(string $pathAndQuery, string $requestId, string $body = ''): string
    {
        return DynadotSignature::compute($this->apiKey, $this->apiSecret, $pathAndQuery, $requestId, $body);
    }

    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]', 'apiSecret' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Registrar credentials must not be serialised.');
    }
}
