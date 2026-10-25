<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Spaceship;

use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * The stored Spaceship secret is the JSON {api_key, api_secret} written by the
 * provider worker (THREAT-MODEL-DOMAIN-PRICING.md SP-2). Both travel only in
 * request headers: the secret as the endpoint's credential header, the key as
 * the allow-listed `X-API-Key` header.
 */
final class SpaceshipCredential
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

    /** The secret, for the endpoint's `X-API-Secret` header, under the stored credential's ID. */
    public function secret(string $credentialId): ProviderCredential
    {
        return new ProviderCredential($credentialId, $this->apiSecret);
    }

    /** @return array{X-API-Key: string} */
    public function keyHeader(): array
    {
        return ['X-API-Key' => $this->apiKey];
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
