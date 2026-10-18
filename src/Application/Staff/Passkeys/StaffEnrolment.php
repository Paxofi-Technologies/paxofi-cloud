<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

use Paxofi\Core\Contracts\TransactionManager;
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
 * Registering passkeys with an invitation (threat model D-11, §6.17). The
 * invitation authorises passkeys for its own member only; the member becomes
 * active with the second passkey, which also uses up the invitation.
 */
final readonly class StaffEnrolment
{
    public const int REQUIRED_PASSKEYS = 2;
    public const int MAX_PASSKEYS = 5;
    public const int CEREMONY_TTL_SECONDS = 300;
    public const int IP_LIMIT_PER_HOUR = 30;
    private const string PURPOSE = 'register';

    public function __construct(
        private StaffPasskeyStore $store,
        private PasskeyCeremonies $ceremonies,
        private CeremonyStore $ceremonyStore,
        private TransactionManager $transactions,
        private RateLimiter $limiter,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{ceremony_id: string, options: array<string, mixed>}
     * @throws PasskeyRejected
     */
    public function begin(#[\SensitiveParameter] string $token, ClientContext $client): array
    {
        $this->throttle($client);
        $tokenHash = $this->tokenHash($token, $client);
        $invitation = $this->store->openInvitationForUpdate($tokenHash, $this->clock->now());
        if ($invitation === null) {
            $this->reject(PasskeyRejected::INVITATION, $client);
        }
        $existing = $this->store->credentialIdsOf($invitation['staff_id']);
        if (count($existing) >= self::MAX_PASSKEYS) {
            $this->reject(PasskeyRejected::LIMIT, $client, $invitation['staff_id']);
        }

        $challenge = random_bytes(32);
        $ceremonyId = PasskeyTokens::newToken();
        $this->ceremonyStore->put(hash('sha256', $ceremonyId), [
            'purpose' => self::PURPOSE,
            'invitation' => $tokenHash,
            'staff_id' => $invitation['staff_id'],
            'challenge' => PasskeyTokens::encode($challenge),
        ], self::CEREMONY_TTL_SECONDS);

        return [
            'ceremony_id' => $ceremonyId,
            'options' => $this->ceremonies->registrationOptions($challenge, $invitation['user_handle'], $invitation['email'], $invitation['full_name'], $existing),
        ];
    }

    /**
     * @return array{passkeys: int, active: bool}
     * @throws PasskeyRejected
     */
    public function finish(#[\SensitiveParameter] string $token, string $ceremonyId, string $responseJson, string $label, ClientContext $client): array
    {
        $this->throttle($client);
        $tokenHash = $this->tokenHash($token, $client);
        $state = PasskeyTokens::isToken($ceremonyId) ? $this->ceremonyStore->take(hash('sha256', $ceremonyId)) : null;
        $challenge = PasskeyTokens::decode($state['challenge'] ?? '');
        if ($state === null || ($state['purpose'] ?? '') !== self::PURPOSE || !hash_equals($state['invitation'] ?? '', $tokenHash) || $challenge === null) {
            $this->reject(PasskeyRejected::CEREMONY, $client);
        }
        $label = trim($label);
        if (mb_strlen($label) > 64 || preg_match('/[\p{C}<>]/u', $label) === 1) {
            $label = '';
        }

        try {
            $result = $this->transactions->transaction(function () use ($tokenHash, $state, $challenge, $responseJson, $label): array {
                $now = $this->clock->now();
                $invitation = $this->store->openInvitationForUpdate($tokenHash, $now);
                if ($invitation === null || $invitation['staff_id'] !== ($state['staff_id'] ?? '')) {
                    throw new PasskeyRejected(PasskeyRejected::INVITATION);
                }
                $existing = $this->store->credentialIdsOf($invitation['staff_id']);
                if (count($existing) >= self::MAX_PASSKEYS) {
                    throw new PasskeyRejected(PasskeyRejected::LIMIT);
                }
                $passkey = $this->ceremonies->verifyRegistration($responseJson, $challenge, $invitation['user_handle'], $invitation['email'], $invitation['full_name'], $existing);
                $count = count($existing) + 1;
                $this->store->addPasskey(bin2hex(random_bytes(16)), $invitation['staff_id'], $passkey, $label !== '' ? $label : 'Passkey ' . $count, $now);
                $active = $count >= self::REQUIRED_PASSKEYS;
                if ($active) {
                    $this->store->completeEnrolment($tokenHash, $invitation['staff_id'], $now);
                }

                return ['staff_id' => $invitation['staff_id'], 'passkeys' => $count, 'active' => $active];
            });
        } catch (PasskeyRejected $e) {
            $this->reject($e->reason, $client, is_string($state['staff_id'] ?? null) ? $state['staff_id'] : null);
        }

        $this->audit->record(AuditEventType::STAFF_PASSKEY_REGISTERED, AuditEvent::SUCCESS, $client, details: ['subject' => $result['staff_id'], 'passkeys' => $result['passkeys']]);
        if ($result['active']) {
            $this->audit->record(AuditEventType::STAFF_ACTIVATED, AuditEvent::SUCCESS, $client, details: ['subject' => $result['staff_id']]);
        }

        return ['passkeys' => $result['passkeys'], 'active' => $result['active']];
    }

    /** @throws PasskeyRejected */
    private function throttle(ClientContext $client): void
    {
        $retry = $this->limiter->hit('staff:enrol:ip:' . ($client->ip !== '' ? $client->ip : 'unknown'), self::IP_LIMIT_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new PasskeyRejected(PasskeyRejected::THROTTLED, $retry);
        }
    }

    /** @throws PasskeyRejected */
    private function tokenHash(string $token, ClientContext $client): string
    {
        if (!PasskeyTokens::isToken($token)) {
            $this->reject(PasskeyRejected::INVITATION, $client);
        }

        return hash('sha256', $token);
    }

    /** @throws PasskeyRejected */
    private function reject(string $reason, ClientContext $client, ?string $staffId = null): never
    {
        $details = ['ceremony' => self::PURPOSE, 'reason' => $reason];
        if ($staffId !== null && preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
            $details['subject'] = $staffId;
        }
        $this->audit->record(AuditEventType::STAFF_PASSKEY_REJECTED, AuditEvent::FAILURE, $client, details: $details);

        throw new PasskeyRejected($reason);
    }
}
