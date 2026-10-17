<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\IdentityStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;

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
    ) {
    }

    /**
     * @param array<array-key, mixed> $data the decoded request body
     * @throws InvalidRegistration
     * @throws RegistrationThrottled
     */
    public function resend(array $data, string $clientIp): void
    {
        $email = self::email($data);

        // The address bucket is the registration one: both endpoints together
        // send at most RegisterCustomer::PER_EMAIL_LIMIT emails an hour (T-47).
        $retry = max(
            $this->limiter->hit('resend:ip:' . hash('sha256', $clientIp), self::PER_IP_LIMIT, RegisterCustomer::WINDOW_SECONDS),
            $this->limiter->hit('register:email:' . $email->hash(), RegisterCustomer::PER_EMAIL_LIMIT, RegisterCustomer::WINDOW_SECONDS),
        );
        if ($retry > 0) {
            throw new RegistrationThrottled($retry);
        }

        $this->transactions->transaction(function () use ($email): void {
            $existing = $this->identities->findByEmailLookupForUpdate($email->hash());
            if ($existing === null) {
                return;
            }
            if ($existing['status'] !== 'pending_verification') {
                $this->outbox->enqueue($email, EmailTemplates::ACCOUNT_EXISTS, ['name' => $existing['full_name']]);

                return;
            }

            $now = $this->clock->now();
            $token = RegisterCustomer::newToken();
            $this->identities->replaceVerificationToken($existing['id'], hash('sha256', $token), $now->modify('+' . RegisterCustomer::TOKEN_TTL_SECONDS . ' seconds'));
            $this->outbox->enqueue($email, EmailTemplates::VERIFY_EMAIL, ['name' => $existing['full_name'], 'token' => $token]);
        });
    }

    /**
     * Exactly one field, `email`; anything else makes the request invalid.
     *
     * @param array<array-key, mixed> $data
     * @throws InvalidRegistration
     */
    private static function email(array $data): EmailAddress
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if ($key !== 'email') {
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
