<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Payments;

use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\GatewayKeyGuard;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * A decrypted gateway credential (PY-2): the JSON the intake stored, read
 * into named fields. The secret key must be of the mode this environment
 * allows, checked before every call (PY-4); the values never appear in debug
 * output and the object refuses serialisation.
 */
final class GatewayCredential
{
    private const string HASH = '/^[\x21-\x7E]{32,128}$/D';

    private function __construct(
        private readonly string $credentialId,
        private readonly string $secretKey,
        private readonly ?string $webhookHash,
    ) {
    }

    /** @throws GatewayUnavailable credential_invalid, key_mode_mismatch */
    public static function from(Gateway $gateway, ProviderCredential $stored, GatewayMode $required): self
    {
        $expected = $gateway === Gateway::Flutterwave ? ['secret_key', 'webhook_secret_hash'] : ['secret_key'];
        try {
            $fields = json_decode($stored->reveal(), true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GatewayUnavailable('credential_invalid');
        }
        if (!is_array($fields) || array_keys($fields) !== $expected) {
            throw new GatewayUnavailable('credential_invalid');
        }
        $key = $fields['secret_key'];
        $hash = $fields['webhook_secret_hash'] ?? null;
        if (!is_string($key) || GatewayKeyGuard::modeOf($gateway, $key) === null) {
            throw new GatewayUnavailable('credential_invalid');
        }
        if ($gateway === Gateway::Flutterwave && (!is_string($hash) || preg_match(self::HASH, $hash) !== 1)) {
            throw new GatewayUnavailable('credential_invalid');
        }
        if (!GatewayKeyGuard::allows($gateway, $key, $required)) {
            throw new GatewayUnavailable('key_mode_mismatch');
        }

        return new self($stored->credentialId, $key, is_string($hash) ? $hash : null);
    }

    /** The secret key as the transport's bearer credential. */
    public function bearer(): ProviderCredential
    {
        return new ProviderCredential($this->credentialId, $this->secretKey);
    }

    /** Paystack signs webhooks with the secret key itself (HMAC-SHA512). */
    public function signingKey(): string
    {
        return $this->secretKey;
    }

    /** Flutterwave's static secret hash, sent back in `verif-hash`. */
    public function webhookHash(): string
    {
        return $this->webhookHash ?? throw new GatewayUnavailable('credential_invalid');
    }

    public function __debugInfo(): array
    {
        return ['credentialId' => $this->credentialId, 'secretKey' => '[redacted]', 'webhookHash' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Gateway credentials must not be serialised.');
    }
}
