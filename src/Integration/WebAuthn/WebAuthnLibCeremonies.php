<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\WebAuthn;

use CBOR\Decoder;
use CBOR\Normalizable;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\EdDSA;
use PaxofiCloud\Application\Contracts\PasskeyCeremonies;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedAssertion;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\StringStream;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Staff passkey ceremonies on web-auth/webauthn-lib (ADR-002; threat model
 * D-10, §6.17). The only place the library is used. Policy: user
 * verification and discoverable credentials required, attestation "none",
 * ES256 and EdDSA only, one relying party (the admin host) and one origin.
 */
final readonly class WebAuthnLibCeremonies implements PasskeyCeremonies
{
    public const int ES256 = -7;
    public const int EDDSA = -8;
    private const int TIMEOUT_MS = 300_000;
    private const int MAX_RESPONSE_BYTES = 65_536;

    private string $rpId;
    private string $origin;
    private Serializer $serializer;
    private AuthenticatorAttestationResponseValidator $attestationValidator;
    private AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct(string $adminUrl, private string $rpName = 'PaxofiCloud Admin')
    {
        $parts = parse_url($adminUrl);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $local = $scheme === 'http' && $host === 'localhost';
        if (!is_array($parts) || $host === '' || ($scheme !== 'https' && !$local)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/' && $parts['path'] !== '')) {
            throw new \InvalidArgumentException('ADMIN_URL must be an https origin (http only for localhost).');
        }
        $this->rpId = strtolower($host);
        $this->origin = $scheme . '://' . $this->rpId . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $attestation = AttestationStatementSupportManager::create([new NoneAttestationStatementSupport()]);
        $serializer = (new WebauthnSerializerFactory($attestation))->create();
        if (!$serializer instanceof Serializer) {
            throw new \LogicException('Unexpected WebAuthn serializer.');
        }
        $this->serializer = $serializer;

        $steps = new CeremonyStepManagerFactory();
        $steps->setAllowedOrigins([$this->origin]);
        $steps->setAlgorithmManager(Manager::create()->add(ES256::create(), new EdDSA()));
        $steps->setAttestationStatementSupportManager($attestation);
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create($steps->creationCeremony());
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create($steps->requestCeremony());
    }

    public function registrationOptions(string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): array
    {
        return $this->toArray($this->creationOptions($challenge, $userHandle, $userName, $displayName, $excludeCredentialIds));
    }

    public function verifyRegistration(string $responseJson, string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): VerifiedPasskey
    {
        $response = $this->load($responseJson)->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }
        $options = $this->creationOptions($challenge, $userHandle, $userName, $displayName, $excludeCredentialIds);
        try {
            $record = $this->attestationValidator->check($response, $options, $this->rpId);
        } catch (\Throwable) {
            throw new PasskeyRejected(PasskeyRejected::INVALID);
        }
        $algorithm = self::algorithmOf($record->credentialPublicKey);
        if (!in_array($algorithm, [self::ES256, self::EDDSA], true) || in_array($record->publicKeyCredentialId, $excludeCredentialIds, true)) {
            throw new PasskeyRejected(PasskeyRejected::INVALID);
        }

        return new VerifiedPasskey(
            $record->publicKeyCredentialId,
            $record->credentialPublicKey,
            $algorithm,
            $record->counter,
            $record->aaguid->toRfc4122(),
            array_values(array_filter($record->transports, static fn (string $t): bool => preg_match('/^[a-z-]{1,16}$/', $t) === 1)),
            $record->backupEligible === true,
            $record->backupStatus === true,
        );
    }

    public function requestOptions(string $challenge): array
    {
        return $this->toArray($this->assertionOptions($challenge));
    }

    public function assertionCredentialId(string $responseJson): string
    {
        $credential = $this->load($responseJson);
        if (!$credential->response instanceof AuthenticatorAssertionResponse || $credential->rawId === '') {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }

        return $credential->rawId;
    }

    public function verifyAssertion(string $responseJson, string $challenge, StoredPasskey $passkey): VerifiedAssertion
    {
        $credential = $this->load($responseJson);
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse || !hash_equals($passkey->credentialId, $credential->rawId)) {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }
        $record = CredentialRecord::create(
            $passkey->credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $passkey->transports === '' ? [] : explode(',', $passkey->transports),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            EmptyTrustPath::create(),
            Uuid::fromString($passkey->aaguid),
            $passkey->publicKey,
            $passkey->userHandle,
            $passkey->signCount,
            null,
            $passkey->backupEligible,
            $passkey->backupState,
            true,
        );
        try {
            $checked = $this->assertionValidator->check($record, $response, $this->assertionOptions($challenge), $this->rpId, $passkey->userHandle);
        } catch (CounterException) {
            throw new PasskeyRejected(PasskeyRejected::COUNTER);
        } catch (\Throwable) {
            throw new PasskeyRejected(PasskeyRejected::INVALID);
        }

        return new VerifiedAssertion($checked->counter, $checked->backupStatus === true);
    }

    /** @param list<string> $excludeCredentialIds */
    private function creationOptions(string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId),
            PublicKeyCredentialUserEntity::create($userName, $userHandle, $displayName),
            $challenge,
            [PublicKeyCredentialParameters::createPk(self::ES256), PublicKeyCredentialParameters::createPk(self::EDDSA)],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(
                static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $id),
                $excludeCredentialIds,
            ),
            self::TIMEOUT_MS,
        );
    }

    private function assertionOptions(string $challenge): PublicKeyCredentialRequestOptions
    {
        // Usernameless: no allowCredentials, so nothing about staff accounts is revealed (§6.17).
        return PublicKeyCredentialRequestOptions::create(
            $challenge,
            $this->rpId,
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        );
    }

    /** @throws PasskeyRejected */
    private function load(string $json): PublicKeyCredential
    {
        if ($json === '' || strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }
        try {
            $credential = $this->deserialize($json);
        } catch (\Throwable) {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }
        // The serializer returns what the JSON holds (a JSON array stays an array), whatever its docblock says.
        if (!$credential instanceof PublicKeyCredential || $credential->type !== PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY) {
            throw new PasskeyRejected(PasskeyRejected::MALFORMED);
        }

        return $credential;
    }

    private function deserialize(string $json): mixed
    {
        return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
    }

    /** @return array<string, mixed> */
    private function toArray(object $options): array
    {
        $normalized = $this->serializer->normalize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        if (!is_array($normalized)) {
            throw new \LogicException('Unexpected WebAuthn options shape.');
        }
        $clean = [];
        foreach ($normalized as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /** The COSE "alg" (label 3) of a credential public key; 0 when unreadable. */
    private static function algorithmOf(string $coseKey): int
    {
        try {
            $object = Decoder::create()->decode(new StringStream($coseKey));
            $map = $object instanceof Normalizable ? $object->normalize() : null;
        } catch (\Throwable) {
            return 0;
        }
        $alg = is_array($map) ? ($map[3] ?? null) : null;

        return is_int($alg) ? $alg : (is_string($alg) && preg_match('/^-?\d+$/', $alg) === 1 ? (int) $alg : 0);
    }
}
