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
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;

/**
 * Adding an optional passkey to a signed-in member (§6.20; registration
 * policy §6.17). A current authenticator code is required, so a stolen
 * session alone cannot plant a lasting way in.
 */
final readonly class StaffPasskeyRegistration
{
    public const int MAX_PASSKEYS = 5;
    public const int CEREMONY_TTL_SECONDS = 300;
    public const int IP_LIMIT_PER_HOUR = 30;
    private const string PURPOSE = 'register';

    public function __construct(
        private StaffPasskeyStore $store,
        private PasskeyCeremonies $ceremonies,
        private CeremonyStore $ceremonyStore,
        private ?StaffSecondFactor $secondFactor,
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
    public function begin(string $staffId, #[\SensitiveParameter] string $code, ClientContext $client): array
    {
        $this->throttle($client);
        if ($this->secondFactor === null || preg_match('/^\d{6}$/', $code) !== 1 || !$this->secondFactor->verifyCode($staffId, $code)) {
            $this->reject(PasskeyRejected::STEP_UP, $client, $staffId);
        }
        $member = $this->store->activeMemberForPasskeys($staffId);
        if ($member === null) {
            $this->reject(PasskeyRejected::CEREMONY, $client, $staffId);
        }
        $existing = $this->store->credentialIdsOf($staffId);
        if (count($existing) >= self::MAX_PASSKEYS) {
            $this->reject(PasskeyRejected::LIMIT, $client, $staffId);
        }

        $challenge = random_bytes(32);
        $ceremonyId = PasskeyTokens::newToken();
        $this->ceremonyStore->put(hash('sha256', $ceremonyId), [
            'purpose' => self::PURPOSE,
            'staff_id' => $staffId,
            'challenge' => PasskeyTokens::encode($challenge),
        ], self::CEREMONY_TTL_SECONDS);

        return [
            'ceremony_id' => $ceremonyId,
            'options' => $this->ceremonies->registrationOptions($challenge, $member['user_handle'], $member['email'], $member['full_name'], $existing),
        ];
    }

    /**
     * @return array{passkeys: int}
     * @throws PasskeyRejected
     */
    public function finish(string $staffId, string $ceremonyId, string $responseJson, string $label, ClientContext $client): array
    {
        $this->throttle($client);
        $state = PasskeyTokens::isToken($ceremonyId) ? $this->ceremonyStore->take(hash('sha256', $ceremonyId)) : null;
        $challenge = PasskeyTokens::decode($state['challenge'] ?? '');
        if ($state === null || ($state['purpose'] ?? '') !== self::PURPOSE || !hash_equals($state['staff_id'] ?? '', $staffId) || $challenge === null) {
            $this->reject(PasskeyRejected::CEREMONY, $client, $staffId);
        }
        $label = trim($label);
        if (mb_strlen($label) > 64 || preg_match('/[\p{C}<>]/u', $label) === 1) {
            $label = '';
        }

        try {
            $count = $this->transactions->transaction(function () use ($staffId, $challenge, $responseJson, $label): int {
                $member = $this->store->activeMemberForPasskeys($staffId);
                if ($member === null) {
                    throw new PasskeyRejected(PasskeyRejected::CEREMONY);
                }
                $existing = $this->store->credentialIdsOf($staffId);
                if (count($existing) >= self::MAX_PASSKEYS) {
                    throw new PasskeyRejected(PasskeyRejected::LIMIT);
                }
                $passkey = $this->ceremonies->verifyRegistration($responseJson, $challenge, $member['user_handle'], $member['email'], $member['full_name'], $existing);
                $count = count($existing) + 1;
                $this->store->addPasskey(bin2hex(random_bytes(16)), $staffId, $passkey, $label !== '' ? $label : 'Passkey ' . $count, $this->clock->now());

                return $count;
            });
        } catch (PasskeyRejected $e) {
            $this->reject($e->reason, $client, $staffId);
        }

        $this->audit->record(AuditEventType::STAFF_PASSKEY_REGISTERED, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId, 'passkeys' => $count]);

        return ['passkeys' => $count];
    }

    /** @throws PasskeyRejected */
    private function throttle(ClientContext $client): void
    {
        $retry = $this->limiter->hit('staff:passkey_add:ip:' . ($client->ip !== '' ? $client->ip : 'unknown'), self::IP_LIMIT_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new PasskeyRejected(PasskeyRejected::THROTTLED, $retry);
        }
    }

    /** @throws PasskeyRejected */
    private function reject(string $reason, ClientContext $client, string $staffId): never
    {
        $details = ['ceremony' => self::PURPOSE, 'reason' => $reason];
        if (preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
            $details['subject'] = $staffId;
        }
        $this->audit->record(AuditEventType::STAFF_PASSKEY_REJECTED, AuditEvent::FAILURE, $client, details: $details);

        throw new PasskeyRejected($reason);
    }
}
