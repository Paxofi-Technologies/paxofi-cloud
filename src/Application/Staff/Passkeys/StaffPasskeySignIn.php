<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\CeremonyStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\PasskeyCeremonies;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\StaffPasskeyStore;

/**
 * Usernameless staff sign-in with a passkey (IAM-008; threat model D-10,
 * T-29, T-30, §6.17). Every failure looks the same to the caller; the audit
 * log keeps the fixed reason. A counter that goes backwards disables that
 * passkey and raises an alert.
 */
final readonly class StaffPasskeySignIn
{
    public const int CEREMONY_TTL_SECONDS = 300;
    public const int IP_LIMIT_PER_MINUTE = 30;
    private const string PURPOSE = 'sign_in';

    public function __construct(
        private StaffPasskeyStore $store,
        private PasskeyCeremonies $ceremonies,
        private CeremonyStore $ceremonyStore,
        private RateLimiter $limiter,
        private Clock $clock,
        private AuditTrail $audit,
        private Logger $logger,
    ) {
    }

    /**
     * @return array{ceremony_id: string, options: array<string, mixed>}
     * @throws PasskeyRejected
     */
    public function begin(ClientContext $client): array
    {
        $this->throttle($client);
        $challenge = random_bytes(32);
        $ceremonyId = PasskeyTokens::newToken();
        $this->ceremonyStore->put(hash('sha256', $ceremonyId), ['purpose' => self::PURPOSE, 'challenge' => PasskeyTokens::encode($challenge)], self::CEREMONY_TTL_SECONDS);

        return ['ceremony_id' => $ceremonyId, 'options' => $this->ceremonies->requestOptions($challenge)];
    }

    /**
     * @return string the signed-in staff ID
     * @throws PasskeyRejected
     */
    public function finish(string $ceremonyId, string $responseJson, ClientContext $client): string
    {
        $this->throttle($client);
        $state = PasskeyTokens::isToken($ceremonyId) ? $this->ceremonyStore->take(hash('sha256', $ceremonyId)) : null;
        $challenge = PasskeyTokens::decode($state['challenge'] ?? '');
        if ($state === null || ($state['purpose'] ?? '') !== self::PURPOSE || $challenge === null) {
            $this->fail(PasskeyRejected::CEREMONY, $client);
        }

        try {
            $credentialId = $this->ceremonies->assertionCredentialId($responseJson);
        } catch (PasskeyRejected $e) {
            $this->fail($e->reason, $client);
        }
        $passkey = $this->store->activePasskeyByCredential(hash('sha256', $credentialId));
        if ($passkey === null) {
            $this->fail(PasskeyRejected::UNKNOWN_CREDENTIAL, $client);
        }

        try {
            $verified = $this->ceremonies->verifyAssertion($responseJson, $challenge, $passkey);
        } catch (PasskeyRejected $e) {
            if ($e->reason === PasskeyRejected::COUNTER) {
                $this->store->disablePasskey($passkey->id, 'counter_regression', $this->clock->now());
                $this->logger->error('staff.passkey.clone_suspected', ['staff_id' => $passkey->staffId, 'passkey_id' => $passkey->id]);
                $this->audit->record(AuditEventType::STAFF_PASSKEY_CLONE_SUSPECTED, AuditEvent::FAILURE, $client, details: ['subject' => $passkey->staffId]);
            }
            $this->fail($e->reason, $client, $passkey->staffId);
        }

        $this->store->recordUse($passkey->id, $verified->signCount, $verified->backupState, $this->clock->now());
        $this->audit->record(AuditEventType::STAFF_SIGNED_IN, AuditEvent::SUCCESS, $client, details: ['subject' => $passkey->staffId]);

        return $passkey->staffId;
    }

    /** @throws PasskeyRejected */
    private function throttle(ClientContext $client): void
    {
        $retry = $this->limiter->hit('staff:sign_in:ip:' . ($client->ip !== '' ? $client->ip : 'unknown'), self::IP_LIMIT_PER_MINUTE, 60);
        if ($retry > 0) {
            throw new PasskeyRejected(PasskeyRejected::THROTTLED, $retry);
        }
    }

    /** @throws PasskeyRejected */
    private function fail(string $reason, ClientContext $client, ?string $staffId = null): never
    {
        $details = ['reason' => $reason];
        if ($staffId !== null && preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
            $details['subject'] = $staffId;
        }
        $this->audit->record(AuditEventType::STAFF_SIGN_IN_FAILED, AuditEvent::FAILURE, $client, details: $details);

        throw new PasskeyRejected($reason);
    }
}
