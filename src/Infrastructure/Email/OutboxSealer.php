<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

/**
 * Seals outbox payloads to the mail worker's X25519 public key with libsodium
 * `crypto_box_seal` (threat model E-3; no home-made cryptography).
 *
 * The web tier is built with the public key only and can seal but never open.
 * Only the worker is built with the secret key. Keys come from the
 * environment, generated on the host by `bin/paxoficloud mail:keygen`:
 *   EMAIL_OUTBOX_PUBLIC_KEY=<base64 32 bytes>   (web + worker)
 *   EMAIL_OUTBOX_SECRET_KEY=<base64 32 bytes>   (worker only)
 */
final class OutboxSealer
{
    private readonly ?string $keyPair;

    private function __construct(private readonly string $publicKey, #[\SensitiveParameter] ?string $secretKey)
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Outbox public key must be 32 bytes.');
        }
        if ($secretKey !== null) {
            if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES || sodium_crypto_box_publickey_from_secretkey($secretKey) !== $publicKey) {
                throw new \InvalidArgumentException('Outbox secret key does not match the public key.');
            }
            $this->keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);
        } else {
            $this->keyPair = null;
        }
    }

    public static function forSealing(string $publicKeyBase64): self
    {
        return new self(self::decode($publicKeyBase64, 'EMAIL_OUTBOX_PUBLIC_KEY'), null);
    }

    public static function forOpening(string $publicKeyBase64, #[\SensitiveParameter] string $secretKeyBase64): self
    {
        return new self(self::decode($publicKeyBase64, 'EMAIL_OUTBOX_PUBLIC_KEY'), self::decode($secretKeyBase64, 'EMAIL_OUTBOX_SECRET_KEY'));
    }

    /** @return array{public: string, secret: string} base64 key pair for the environment */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_box_keypair();

        return [
            'public' => base64_encode(sodium_crypto_box_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_box_secretkey($pair)),
        ];
    }

    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        return sodium_bin2base64(sodium_crypto_box_seal($plaintext, $this->publicKey), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function open(string $sealed): string
    {
        if ($this->keyPair === null) {
            throw new \LogicException('This sealer holds no secret key; only the mail worker can open outbox payloads.');
        }
        try {
            $plaintext = sodium_crypto_box_seal_open(sodium_base642bin($sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $this->keyPair);
        } catch (\SodiumException) {
            $plaintext = false;
        }
        if ($plaintext === false) {
            throw new \UnexpectedValueException('Outbox payload failed authentication.');
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
        if ($raw === false || strlen($raw) !== 32) {
            throw new \InvalidArgumentException($name . ' must be base64 of 32 bytes.');
        }

        return $raw;
    }
}
