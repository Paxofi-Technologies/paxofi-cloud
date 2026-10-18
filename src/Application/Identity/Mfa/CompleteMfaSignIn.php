<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\MfaChallengeStore;
use PaxofiCloud\Application\Contracts\MfaStore;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Identity\Session\IssuedSession;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Identity\SignIn\AccountUnavailable;

/**
 * The second sign-in step (SRS IAM-007; threat model T-13, T-14, T-18, §6.13):
 * a live ticket from the password step plus a TOTP or recovery code gives a
 * new session. 5 wrong codes end the ticket; 5 per identity in 15 minutes
 * lock the step for that identity whatever ticket is used.
 */
final readonly class CompleteMfaSignIn
{
    public const int TICKET_TTL_SECONDS = 300;
    public const int MAX_ATTEMPTS = 5;
    public const int LOCK_SECONDS = 900;

    public function __construct(
        private MfaChallengeStore $challenges,
        private MfaStore $mfa,
        private SignInStore $identities,
        private Sessions $sessions,
        private Clock $clock,
        private Logger $logger,
        private ?MfaCrypto $crypto,
        private AuditTrail $audit,
    ) {
    }

    /** Called by SignIn once the password is right and TOTP is on. */
    public function start(string $identityId, string $accountId): MfaPending
    {
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->challenges->save(self::hash($ticket), $identityId, $accountId, self::TICKET_TTL_SECONDS);

        return new MfaPending($ticket);
    }

    /**
     * @param array<array-key, mixed> $data `{code}` or `{recovery_code}`
     * @throws MfaTicketInvalid|MfaThrottled|InvalidMfaInput|MfaUnavailable|AccountUnavailable
     */
    public function complete(#[\SensitiveParameter] ?string $ticket, #[\SensitiveParameter] array $data, ClientContext $client): IssuedSession
    {
        $useRecovery = !array_key_exists('code', $data);
        $field = $useRecovery ? 'recovery_code' : 'code';
        $value = MfaSettings::fields($data, [$field])[$field];

        if ($ticket === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket) !== 1) {
            throw new MfaTicketInvalid();
        }
        $ticketHash = self::hash($ticket);
        $pending = $this->challenges->find($ticketHash) ?? throw new MfaTicketInvalid();
        $identityId = $pending['identity_id'];

        if ($this->challenges->identityFailures($identityId) >= self::MAX_ATTEMPTS) {
            $this->challenges->delete($ticketHash);
            $this->audit->record(AuditEventType::MFA_FAILED, AuditEvent::FAILURE, $client, $identityId, $pending['account_id'], ['reason' => 'locked']);
            throw new MfaThrottled(self::LOCK_SECONDS);
        }
        $crypto = $this->crypto ?? throw new MfaUnavailable();

        $factor = new SecondFactor($this->mfa, $crypto, $this->clock);
        $ok = $useRecovery ? $factor->verifyRecoveryCode($identityId, $value) : $factor->verifyCode($identityId, $value);
        $method = $useRecovery ? 'recovery_code' : 'totp';
        if (!$ok) {
            $attempts = $this->challenges->recordAttempt($ticketHash);
            $failures = $this->challenges->recordIdentityFailure($identityId);
            $this->audit->record(AuditEventType::MFA_FAILED, AuditEvent::FAILURE, $client, $identityId, $pending['account_id'], ['method' => $method, 'reason' => 'incorrect']);
            if ($attempts >= self::MAX_ATTEMPTS || $failures >= self::MAX_ATTEMPTS) {
                $this->challenges->delete($ticketHash);
                $this->logger->warning('auth.mfa.locked', ['identity_id' => $identityId]);
                $this->audit->record(AuditEventType::MFA_LOCKED, AuditEvent::FAILURE, $client, $identityId, $pending['account_id']);
            }

            throw new InvalidMfaInput([$field => 'incorrect']);
        }

        $this->challenges->delete($ticketHash);
        $profile = $this->identities->findProfile($identityId);
        if ($profile === null || $profile['status'] !== 'active') {
            throw new AccountUnavailable();
        }
        if ($useRecovery) {
            $this->logger->info('auth.mfa.recovery_code_used', ['identity_id' => $identityId]);
        }

        $issued = $this->sessions->issue($identityId, $pending['account_id'], $client);
        $this->audit->record(AuditEventType::MFA_SUCCEEDED, AuditEvent::SUCCESS, $client, $identityId, $pending['account_id'], ['method' => $method]);
        $this->audit->record(AuditEventType::SIGN_IN_SUCCEEDED, AuditEvent::SUCCESS, $client, $identityId, $pending['account_id'], ['method' => 'password_' . $method]);

        return $issued;
    }

    public static function hash(#[\SensitiveParameter] string $ticket): string
    {
        return hash('sha256', $ticket);
    }
}
