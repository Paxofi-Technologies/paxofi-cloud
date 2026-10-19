<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\SignIn;

use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\PasswordHasher;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\CeremonyStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\PasswordRehashPolicy;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\StaffCredentialStore;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyTokens;

/**
 * Staff sign-in with password, then an authenticator code, then a code sent
 * by email (CEO decision 19 Oct 2026; threat model §6.20, T-29, R-5).
 *
 * Between steps the browser holds only a ticket. Every step takes the ticket
 * out of the store and, when the sign-in goes on, writes it back under a new
 * ID with the original expiry: a ticket works once, and two requests racing
 * with one ticket cannot both run.
 */
final readonly class StaffCodeSignIn
{
    public const int TICKET_SECONDS = 600;
    public const int MAX_WRONG_CODES_PER_TICKET = 5;
    public const int IP_LIMIT_PER_MINUTE = 30;
    public const int ADDRESS_LIMIT = 10;
    public const int ADDRESS_WINDOW_SECONDS = 900;
    public const int MEMBER_CODE_LIMIT = 20;
    public const int MEMBER_CODE_WINDOW_SECONDS = 900;
    public const int EMAIL_RESEND_SECONDS = 60;
    public const int MAX_EMAIL_RESENDS = 2;
    private const string PURPOSE = 'staff_code_sign_in';

    public function __construct(
        private StaffCredentialStore $store,
        private PasswordHasher $hasher,
        private PasswordRehashPolicy $rehash,
        private ?StaffSecondFactor $secondFactor,
        private CeremonyStore $tickets,
        private RateLimiter $limiter,
        private EmailOutbox $outbox,
        private Clock $clock,
        private AuditTrail $audit,
        private Logger $logger,
    ) {
    }

    /**
     * Step 1. Every failure is the same; only a member who can finish all
     * three steps gets a ticket.
     *
     * @throws StaffSignInRefused
     */
    public function password(string $email, #[\SensitiveParameter] string $password, ClientContext $client): StaffSignInStep
    {
        if ($this->secondFactor === null) {
            throw new StaffSignInRefused(StaffSignInRefused::UNAVAILABLE);
        }
        try {
            $lookup = (new EmailAddress($email))->hash();
        } catch (\InvalidArgumentException) {
            $lookup = null;
        }
        // Both limits run before the hash (T-11); the address limit counts every address, known or not.
        $retry = $this->limiter->hit('staff:password:ip:' . hash('sha256', $client->ip), self::IP_LIMIT_PER_MINUTE, 60);
        if ($lookup !== null) {
            $retry = max($retry, $this->limiter->hit('staff:password:address:' . $lookup, self::ADDRESS_LIMIT, self::ADDRESS_WINDOW_SECONDS));
        }
        if ($retry > 0) {
            throw new StaffSignInRefused(StaffSignInRefused::THROTTLED, $retry);
        }

        $member = $lookup === null ? null : $this->store->findForPassword($lookup);
        $hash = $member['password_hash'] ?? null;
        // D-7: one Argon2id verification on every path.
        $matches = $this->hasher->verify($password, $hash ?? SignIn::DUMMY_HASH) && $hash !== null;
        $reason = match (true) {
            $member === null => 'unknown_account',
            $hash === null => 'no_password',
            !$matches => 'wrong_password',
            $member['status'] !== 'active' => 'not_active',
            ($this->store->totp($member['id'])['confirmed'] ?? false) !== true => 'no_authenticator',
            default => null,
        };
        if ($reason !== null) {
            $this->fail('password', $reason, $client, $member['id'] ?? null);
            throw new StaffSignInRefused(StaffSignInRefused::FAILED);
        }

        if ($this->rehash->needsRehash($hash)) {
            $this->store->replacePasswordHash($member['id'], $this->hasher->hash($password));
        }
        $this->audit->record(AuditEventType::STAFF_PASSWORD_ACCEPTED, AuditEvent::SUCCESS, $client, details: ['subject' => $member['id']]);

        $expires = $this->clock->now()->getTimestamp() + self::TICKET_SECONDS;

        return $this->issue(['stage' => StaffSignInStep::TOTP, 'staff_id' => $member['id'], 'attempts' => '0', 'expires' => (string) $expires]);
    }

    /**
     * Step 2: an authenticator code, or a recovery code. Only now is an email sent.
     *
     * @throws StaffSignInRefused
     */
    public function authenticatorCode(#[\SensitiveParameter] ?string $ticket, #[\SensitiveParameter] string $code, ClientContext $client): StaffSignInStep
    {
        $secondFactor = $this->secondFactor ?? throw new StaffSignInRefused(StaffSignInRefused::UNAVAILABLE);
        $state = $this->take($ticket, StaffSignInStep::TOTP, $client);
        $staffId = $state['staff_id'];

        $method = preg_match('/^\d{6}$/', $code) === 1 ? 'totp' : 'recovery_code';
        $valid = $method === 'totp' ? $secondFactor->verifyCode($staffId, $code) : $secondFactor->verifyRecoveryCode($staffId, $code);
        if (!$valid) {
            $this->wrongCode('authenticator', $state, $client);
        }
        $this->audit->record(AuditEventType::STAFF_CODE_ACCEPTED, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId, 'method' => $method]);

        return $this->sendEmailCode(['stage' => StaffSignInStep::EMAIL, 'resends' => '0'] + $state, $client);
    }

    /**
     * Step 2b: a new email code, which replaces the old one.
     *
     * @throws StaffSignInRefused
     */
    public function resendEmailCode(#[\SensitiveParameter] ?string $ticket, ClientContext $client): StaffSignInStep
    {
        $state = $this->take($ticket, StaffSignInStep::EMAIL, $client);
        $wait = (int) $state['sent'] + self::EMAIL_RESEND_SECONDS - $this->clock->now()->getTimestamp();
        if ((int) $state['resends'] >= self::MAX_EMAIL_RESENDS) {
            $step = $this->issue($state);
            throw new StaffSignInRefused(StaffSignInRefused::THROTTLED, $step->expiresIn, $step->ticket);
        }
        if ($wait > 0) {
            $step = $this->issue($state);
            throw new StaffSignInRefused(StaffSignInRefused::THROTTLED, $wait, $step->ticket);
        }

        return $this->sendEmailCode(['resends' => (string) ((int) $state['resends'] + 1)] + $state, $client);
    }

    /**
     * Step 3: the emailed code. Returns the staff ID to start a session for.
     *
     * @throws StaffSignInRefused
     */
    public function emailCode(#[\SensitiveParameter] ?string $ticket, #[\SensitiveParameter] string $code, ClientContext $client): string
    {
        $state = $this->take($ticket, StaffSignInStep::EMAIL, $client);
        $staffId = $state['staff_id'];
        if (preg_match('/^\d{6}$/', $code) !== 1 || !hash_equals($state['email_code'], self::codeHash($state['salt'], $code))) {
            $this->wrongCode('email', $state, $client);
        }
        // The member may have been disabled while the code was on its way.
        if (($this->store->member($staffId)['status'] ?? null) !== 'active') {
            $this->fail('email', 'not_active', $client, $staffId);
            throw new StaffSignInRefused(StaffSignInRefused::EXPIRED);
        }
        $this->audit->record(AuditEventType::STAFF_SIGNED_IN, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId, 'method' => 'password_codes']);

        return $staffId;
    }

    /**
     * @param array<string, string> $state
     * @throws StaffSignInRefused
     */
    private function sendEmailCode(array $state, ClientContext $client): StaffSignInStep
    {
        $staffId = $state['staff_id'];
        $member = $this->store->member($staffId);
        if ($member === null) {
            throw new StaffSignInRefused(StaffSignInRefused::EXPIRED);
        }
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $salt = PasskeyTokens::newToken();
        try {
            $this->outbox->enqueue(new EmailAddress($member['email']), EmailTemplates::STAFF_SIGN_IN_CODE, [
                'name' => $member['full_name'],
                'code' => $code,
                'device' => $client->device()->label(),
                'location' => $client->location(),
                'ip' => $client->ip,
                'time' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (\Throwable $e) {
            // Without the email the sign-in cannot finish; the ticket is not written back.
            $this->logger->error('staff.sign_in_code_enqueue_failed', ['staff_id' => $staffId, 'error' => $e::class]);
            throw new StaffSignInRefused(StaffSignInRefused::UNAVAILABLE);
        }
        $this->audit->record(AuditEventType::STAFF_EMAIL_CODE_SENT, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId]);

        return $this->issue(['email_code' => self::codeHash($salt, $code), 'salt' => $salt, 'sent' => (string) $this->clock->now()->getTimestamp()] + $state);
    }

    /**
     * Counts a wrong code: the ticket goes on under a new ID, or ends at the limit.
     *
     * @param array<string, string> $state
     * @throws StaffSignInRefused
     */
    private function wrongCode(string $step, array $state, ClientContext $client): never
    {
        $attempts = (int) $state['attempts'] + 1;
        $this->fail($step, 'wrong_code', $client, $state['staff_id']);
        if ($attempts >= self::MAX_WRONG_CODES_PER_TICKET) {
            throw new StaffSignInRefused(StaffSignInRefused::EXPIRED);
        }
        $next = $this->issue(['attempts' => (string) $attempts] + $state);

        throw new StaffSignInRefused(StaffSignInRefused::CODE_INVALID, ticket: $next->ticket);
    }

    /**
     * Takes the ticket out of the store; it is written back only if the sign-in goes on.
     *
     * @return array{stage: string, staff_id: string, attempts: string, expires: string, resends: string, sent: string, email_code: string, salt: string}
     * @throws StaffSignInRefused
     */
    private function take(?string $ticket, string $stage, ClientContext $client): array
    {
        $state = $ticket !== null && PasskeyTokens::isToken($ticket) ? $this->tickets->take(hash('sha256', $ticket)) : null;
        if ($state === null || ($state['purpose'] ?? '') !== self::PURPOSE || ($state['stage'] ?? '') !== $stage
            || preg_match('/^[a-f0-9]{32}$/', $state['staff_id'] ?? '') !== 1
            || (int) ($state['expires'] ?? 0) <= $this->clock->now()->getTimestamp()) {
            throw new StaffSignInRefused(StaffSignInRefused::EXPIRED);
        }
        $staffId = $state['staff_id'];
        // §6.20: a ceiling per member across tickets, so many passwords-correct tickets cannot add up.
        $retry = $this->limiter->hit('staff:codes:member:' . $staffId, self::MEMBER_CODE_LIMIT, self::MEMBER_CODE_WINDOW_SECONDS);
        if ($retry > 0) {
            $this->fail($stage === StaffSignInStep::TOTP ? 'authenticator' : 'email', 'member_locked', $client, $staffId);
            throw new StaffSignInRefused(StaffSignInRefused::THROTTLED, $retry);
        }

        return [
            'stage' => $state['stage'],
            'staff_id' => $staffId,
            'attempts' => $state['attempts'] ?? '0',
            'expires' => $state['expires'] ?? '0',
            'resends' => $state['resends'] ?? '0',
            'sent' => $state['sent'] ?? '0',
            'email_code' => $state['email_code'] ?? '',
            'salt' => $state['salt'] ?? '',
        ];
    }

    /** @param array<string, string> $state */
    private function issue(array $state): StaffSignInStep
    {
        $remaining = (int) $state['expires'] - $this->clock->now()->getTimestamp();
        if ($remaining < 1) {
            throw new StaffSignInRefused(StaffSignInRefused::EXPIRED);
        }
        $ticket = PasskeyTokens::newToken();
        $this->tickets->put(hash('sha256', $ticket), ['purpose' => self::PURPOSE] + $state, $remaining);

        return new StaffSignInStep($ticket, $state['stage'], $remaining);
    }

    /**
     * Salted, so the stored value is not a plain lookup; the code is still only
     * usable with the ticket, which the store holds only as a hash (§6.20).
     */
    private static function codeHash(string $salt, #[\SensitiveParameter] string $code): string
    {
        return hash('sha256', $salt . '|' . $code);
    }

    private function fail(string $step, string $reason, ClientContext $client, ?string $staffId): void
    {
        $details = ['step' => $step, 'reason' => $reason];
        if ($staffId !== null && preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
            $details['subject'] = $staffId;
        }
        $this->audit->record(AuditEventType::STAFF_SIGN_IN_FAILED, AuditEvent::FAILURE, $client, details: $details);
    }
}
