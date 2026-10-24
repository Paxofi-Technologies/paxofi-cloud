<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * The stored DomainNameAPI secret is the JSON {reseller_id, api_key} written by
 * the provider worker (PR #97). It is split and checked here, in the worker,
 * immediately before use (DR-3). Neither value is ever logged or returned.
 */
final class DomainNameApiCredential
{
    private function __construct(
        public readonly string $resellerId,
        public readonly ProviderCredential $apiKey,
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
        if (!is_array($fields) || array_keys($fields) !== ['reseller_id', 'api_key']) {
            throw new RegistrarUnavailable('credential_invalid');
        }
        $resellerId = $fields['reseller_id'];
        $apiKey = $fields['api_key'];
        if (!is_string($resellerId) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $resellerId) !== 1
            || !is_string($apiKey) || preg_match('/^[\x21-\x7E]{8,256}$/', $apiKey) !== 1) {
            throw new RegistrarUnavailable('credential_invalid');
        }

        return new self($resellerId, new ProviderCredential($stored->credentialId, $apiKey));
    }

    public function __debugInfo(): array
    {
        return ['resellerId' => '[redacted]', 'apiKey' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Registrar credentials must not be serialised.');
    }
}
