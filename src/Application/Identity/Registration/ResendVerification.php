<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\IdentityStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Challenge\ChallengeUnavailable;

/**
 * "Resend the verification email" (SRS IAM-002; threat model T-49).
 *
 * The caller always gets the same outcome for a well-formed address. A pending
 * registration gets a fresh link (older links stop working, T-46); an existing
 * account gets the account-exists email; an unknown address gets nothing.
 */
final readonly class ResendVerification
{
    public const int PER_IP_LIMIT = 10;

    public function __construct(
        private IdentityStore $identities,
        private EmailOutbox $outbox,
        private TransactionManager $transactions,
        private RateLimiter $limiter,
        private Clock $clock,
        private ChallengeGate $challenge,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data the decoded request body
     * @throws InvalidRegistration
     * @throws RegistrationThrottled
     * @throws ChallengeRequired
     * @throws ChallengeUnavailable
     */
    public function resend(array $data, ClientContext $client): void
    {
        $email = self::email($data);

        // The address bucket is the registration one: both endpoints together
        // send at most RegisterCustomer::PER_EMAIL_LIMIT emails an hour (T-47).
        $retry = max(
            $this->limiter->hit('resend:ip:' . hash('sha256', $client->ip), self::PER_IP_LIMIT, RegisterCustomer::WINDOW_SECONDS),
            $this->limiter->hit('register:email:' . $email->hash(), RegisterCustomer::PER_EMAIL_LIMIT, RegisterCustomer::WINDOW_SECONDS),
        );
        if ($retry > 0) {
            throw new RegistrationThrottled($retry);
        }
        $this->challenge->assertHuman(ChallengeGate::token($data), ChallengeGate::RESEND, $client->ip);

        $resentTo = $this->transactions->transaction(function () use ($email): ?string {
            $existing = $this->identities->findByEmailLookupForUpdate($email->hash());
            if ($existing === null) {
                return null;
            }
            if ($existing['status'] !== 'pending_verification') {
                $this->outbox->enqueue($email, EmailTemplates::ACCOUNT_EXISTS, ['name' => $existing['full_name']]);

                return null;
            }

            $now = $this->clock->now();
            $token = RegisterCustomer::newToken();
            $this->identities->replaceVerificationToken($existing['id'], hash('sha256', $token), $now->modify('+' . RegisterCustomer::TOKEN_TTL_SECONDS . ' seconds'));
            $this->outbox->enqueue($email, EmailTemplates::VERIFY_EMAIL, ['name' => $existing['full_name'], 'token' => $token]);

            return $existing['id'];
        });
        if (is_string($resentTo)) {
            $this->audit->record(AuditEventType::VERIFICATION_RESENT, AuditEvent::SUCCESS, $client, $resentTo);
        }
    }

    /**
     * Exactly `email` (plus the challenge token); anything else makes the request invalid.
     *
     * @param array<array-key, mixed> $data
     * @throws InvalidRegistration
     */
    private static function email(array $data): EmailAddress
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if ($key !== 'email' && $key !== ChallengeGate::FIELD) {
                $errors[is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) === 1 ? $key : '_unknown'] = 'unknown_field';
            }
        }
        $raw = $data['email'] ?? null;
        if (!is_string($raw)) {
            throw new InvalidRegistration($errors + ['email' => 'required']);
        }
        try {
            $email = new EmailAddress($raw);
        } catch (\InvalidArgumentException) {
            throw new InvalidRegistration($errors + ['email' => 'invalid']);
        }
        if ($errors !== []) {
            throw new InvalidRegistration($errors);
        }

        return $email;
    }
}
