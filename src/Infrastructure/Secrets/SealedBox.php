<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use PaxofiCloud\Application\Contracts\CredentialSealer;

/**
 * libsodium `crypto_box_seal` to an X25519 key pair (provider connections
 * threat model PC-1; no home-made cryptography). Built with the public key
 * only, it can seal but never open: that is the web tier. Only the provider
 * worker is built with the secret key.
 *   CREDENTIAL_INTAKE_PUBLIC_KEY=<base64 32 bytes>   (web tier + worker)
 *   CREDENTIAL_INTAKE_SECRET_KEY=<base64 32 bytes>   (provider worker only)
 */
final class SealedBox implements CredentialSealer
{
    private readonly ?string $keyPair;

    private function __construct(private readonly string $publicKey, #[\SensitiveParameter] ?string $secretKey)
    {
        if ($secretKey !== null) {
            if (sodium_crypto_box_publickey_from_secretkey($secretKey) !== $publicKey) {
                throw new \InvalidArgumentException('CREDENTIAL_INTAKE_SECRET_KEY does not match the public key.');
            }
            $this->keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);
        } else {
            $this->keyPair = null;
        }
    }

    public static function forSealing(string $publicKeyBase64): self
    {
        return new self(self::decode($publicKeyBase64, 'CREDENTIAL_INTAKE_PUBLIC_KEY'), null);
    }

    public static function forOpening(string $publicKeyBase64, #[\SensitiveParameter] string $secretKeyBase64): self
    {
        return new self(self::decode($publicKeyBase64, 'CREDENTIAL_INTAKE_PUBLIC_KEY'), self::decode($secretKeyBase64, 'CREDENTIAL_INTAKE_SECRET_KEY'));
    }

    /** @return array{public: string, secret: string} base64 key pair for the environment */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_box_keypair();
        $result = ['public' => base64_encode(sodium_crypto_box_publickey($pair)), 'secret' => base64_encode(sodium_crypto_box_secretkey($pair))];
        sodium_memzero($pair);

        return $result;
    }

    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        return sodium_bin2base64(sodium_crypto_box_seal($plaintext, $this->publicKey), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** @throws \UnexpectedValueException when the box was not sealed to this key or was altered */
    public function open(string $sealed): string
    {
        if ($this->keyPair === null) {
            throw new \LogicException('This box holds no secret key; only the provider worker can open credential intake.');
        }
        try {
            $plaintext = sodium_crypto_box_seal_open(sodium_base642bin($sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $this->keyPair);
        } catch (\SodiumException) {
            $plaintext = false;
        }
        if ($plaintext === false) {
            throw new \UnexpectedValueException('Credential intake failed authentication.');
        }

        return $plaintext;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['canOpen' => $this->keyPair !== null];
    }

    private static function decode(string $base64, string $name): string
    {
        $raw = base64_decode(trim($base64), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException($name . ' must be base64 of 32 bytes.');
        }

        return $raw;
    }
}
