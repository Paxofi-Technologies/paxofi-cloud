<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * A test-only WebAuthn authenticator: real keys and signatures, attestation
 * "none", so ceremonies run end to end through the verifier (§6.17).
 */
final class SoftwareAuthenticator
{
    public string $credentialId;
    public int $signCount;
    public bool $userVerified = true;
    public string $origin;
    /** Signs with this authenticator's key but claims another credential ID (tests). */
    public ?string $claimCredentialId = null;
    public string $type = 'public-key';

    private \OpenSSLAsymmetricKey|string $privateKey;
    private string $coseKey;

    public function __construct(string $origin, public readonly int $algorithm = -7, int $signCount = 0)
    {
        $this->origin = $origin;
        $this->signCount = $signCount;
        $this->credentialId = random_bytes(32);
        if ($algorithm === -7) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $details = $key !== false ? openssl_pkey_get_details($key) : false;
            $x = is_array($details) && is_array($details['ec'] ?? null) ? ($details['ec']['x'] ?? null) : null;
            $y = is_array($details) && is_array($details['ec'] ?? null) ? ($details['ec']['y'] ?? null) : null;
            if ($key === false || !is_string($x) || !is_string($y)) {
                throw new \RuntimeException('EC key generation failed.');
            }
            $this->privateKey = $key;
            $this->coseKey = (string) MapObject::create()
                ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
                ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
                ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
                ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($x, 32, "\0", STR_PAD_LEFT)))
                ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($y, 32, "\0", STR_PAD_LEFT)));
        } elseif ($algorithm === -8) {
            $pair = sodium_crypto_sign_keypair();
            $this->privateKey = sodium_crypto_sign_secretkey($pair);
            $this->coseKey = (string) MapObject::create()
                ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(1))
                ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-8))
                ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(6))
                ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(sodium_crypto_sign_publickey($pair)));
        } else {
            throw new \InvalidArgumentException('Only ES256 and EdDSA test keys.');
        }
    }

    /** @param array<string, mixed> $options registration options as the server sent them */
    public function register(array $options): string
    {
        $rp = $options['rp'] ?? null;
        $rpId = is_array($rp) && is_string($rp['id'] ?? null) ? $rp['id'] : '';
        $clientData = $this->clientData('webauthn.create', self::stringOption($options, 'challenge'));
        $authData = hash('sha256', $rpId, true)
            . chr(0x01 | 0x40 | ($this->userVerified ? 0x04 : 0))
            . pack('N', $this->signCount)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $this->coseKey;
        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => $this->type,
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestationObject),
                'transports' => ['internal'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $options request options as the server sent them */
    public function assert(array $options, string $userHandle): string
    {
        $this->signCount++;
        $rpId = self::stringOption($options, 'rpId');
        $clientData = $this->clientData('webauthn.get', self::stringOption($options, 'challenge'));
        $authData = hash('sha256', $rpId, true) . chr(0x01 | ($this->userVerified ? 0x04 : 0)) . pack('N', $this->signCount);
        $signed = $authData . hash('sha256', $clientData, true);
        if ($this->algorithm === -7) {
            $ok = openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
            if (!$ok || !is_string($signature)) {
                throw new \RuntimeException('Signing failed.');
            }
        } else {
            if (!is_string($this->privateKey) || $this->privateKey === '') {
                throw new \RuntimeException('Missing EdDSA key.');
            }
            $signature = sodium_crypto_sign_detached($signed, $this->privateKey);
        }
        $id = $this->claimCredentialId ?? $this->credentialId;

        return json_encode([
            'id' => self::b64($id),
            'rawId' => self::b64($id),
            'type' => $this->type,
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => self::b64($userHandle),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $options */
    private static function stringOption(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Options lack ' . $key);
        }

        return $value;
    }
}
