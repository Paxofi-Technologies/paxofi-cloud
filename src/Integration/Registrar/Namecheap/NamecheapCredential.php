<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * The stored Namecheap secret is the JSON {api_user, username, api_key,
 * client_ip} written by the provider worker. It is split and checked here,
 * in the worker, immediately before use (NC-3). The values only ever travel
 * in a POST body; none is logged or returned.
 */
final class NamecheapCredential
{
    private function __construct(
        public readonly string $apiUser,
        public readonly string $username,
        private readonly string $apiKey,
        public readonly string $clientIp,
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
        if (!is_array($fields) || array_keys($fields) !== ['api_user', 'username', 'api_key', 'client_ip']) {
            throw new RegistrarUnavailable('credential_invalid');
        }
        ['api_user' => $apiUser, 'username' => $username, 'api_key' => $apiKey, 'client_ip' => $clientIp] = $fields;
        if (!is_string($apiUser) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $apiUser) !== 1
            || !is_string($username) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $username) !== 1
            || !is_string($apiKey) || preg_match('/^[\x21-\x7E]{8,128}$/', $apiKey) !== 1
            || !is_string($clientIp) || !self::isPublicIpv4($clientIp)) {
            throw new RegistrarUnavailable('credential_invalid');
        }

        return new self($apiUser, $username, $apiKey, $clientIp);
    }

    /**
     * The global parameters every call sends, in the POST body only (NC-3).
     *
     * @return array<string, string>
     */
    public function formFields(): array
    {
        return [
            'ApiUser' => $this->apiUser,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->username,
            'ClientIp' => $this->clientIp,
        ];
    }

    /** The address Namecheap must have whitelisted: a plain public IPv4 address. */
    public static function isPublicIpv4(string $value): bool
    {
        return preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}$/', $value) === 1
            && ProviderHttpClient::isPublicAddress($value);
    }

    public function __debugInfo(): array
    {
        return ['apiUser' => '[redacted]', 'username' => '[redacted]', 'apiKey' => '[redacted]', 'clientIp' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Registrar credentials must not be serialised.');
    }
}
