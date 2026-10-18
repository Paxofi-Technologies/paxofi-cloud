<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedAssertion;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;

/**
 * WebAuthn ceremonies for staff (D-10, ADR-002). Options are rebuilt from the
 * same inputs at verification time, so nothing but the challenge needs to be
 * remembered between the two requests. Responses are the JSON the browser's
 * PublicKeyCredential serialises to.
 */
interface PasskeyCeremonies
{
    /**
     * @param list<string> $excludeCredentialIds raw credential IDs already registered
     * @return array<string, mixed> options for navigator.credentials.create()
     */
    public function registrationOptions(string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): array;

    /**
     * @param list<string> $excludeCredentialIds
     * @throws PasskeyRejected
     */
    public function verifyRegistration(string $responseJson, string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): VerifiedPasskey;

    /** @return array<string, mixed> options for navigator.credentials.get() */
    public function requestOptions(string $challenge): array;

    /**
     * The raw credential ID an assertion claims, before it is verified.
     *
     * @throws PasskeyRejected
     */
    public function assertionCredentialId(string $responseJson): string;

    /** @throws PasskeyRejected COUNTER when the sign counter went backwards (T-30) */
    public function verifyAssertion(string $responseJson, string $challenge, StoredPasskey $passkey): VerifiedAssertion;
}
