<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

use Paxofi\Core\Contracts\PasswordHasher;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\IdentityStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Email\EmailTemplates;

/**
 * Customer self-registration (SRS IAM-001, IAM-003; threat model T-01..T-04,
 * T-06, T-46, T-47).
 *
 * The caller always gets the same outcome for valid input, whether the address
 * is new, pending or already active (D-7): the work done is the same (one
 * Argon2id hash, one transaction, one queued email), only the email differs.
 */
final readonly class RegisterCustomer
{
    public const int PER_IP_LIMIT = 5;
    public const int PER_EMAIL_LIMIT = 3;
    public const int WINDOW_SECONDS = 3600;
    public const int TOKEN_TTL_SECONDS = 86_400;
    private const int MAX_ATTEMPTS = 2;

    public function __construct(
        private IdentityStore $identities,
        private EmailOutbox $outbox,
        private PasswordHasher $hasher,
        private TransactionManager $transactions,
        private RateLimiter $limiter,
        private Clock $clock,
        private string $termsVersion,
        private BreachedPasswordCheck $breaches,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $termsVersion) !== 1) {
            throw new \InvalidArgumentException('Terms version must be 1-32 safe characters.');
        }
    }

    /**
     * @param array<array-key, mixed> $data the decoded request body
     * @throws InvalidRegistration
     * @throws RegistrationThrottled
     */
    public function register(#[\SensitiveParameter] array $data, string $clientIp): void
    {
        $input = RegistrationInput::fromArray($data);

        // Rate limits run before the expensive hash (T-11) and count only
        // well-formed attempts, so a typo does not lock a customer out.
        $retry = max(
            $this->limiter->hit('register:ip:' . hash('sha256', $clientIp), self::PER_IP_LIMIT, self::WINDOW_SECONDS),
            $this->limiter->hit('register:email:' . $input->email->hash(), self::PER_EMAIL_LIMIT, self::WINDOW_SECONDS),
        );
        if ($retry > 0) {
            throw new RegistrationThrottled($retry);
        }

        // T-06: a password already seen in a breach is refused. The lookup fails
        // open (null) so an outage of the range API never blocks sign-up.
        if ($this->breaches->isBreached($input->password) === true) {
            throw new InvalidRegistration(['password' => 'breached']);
        }

        $passwordHash = $this->hasher->hash($input->password);
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->transactions->transaction(fn () => $this->store($input, $passwordHash));

                return;
            } catch (\PDOException $e) {
                // Two first-time registrations for one address can collide on
                // the unique index or deadlock on its gap lock. Retry once: the
                // second pass sees the committed row and takes the pending path.
                if ($attempt >= self::MAX_ATTEMPTS || !in_array((string) $e->getCode(), ['23000', '40001'], true)) {
                    throw $e;
                }
            }
        }
    }

    private function store(RegistrationInput $input, string $passwordHash): void
    {
        $now = $this->clock->now();
        $existing = $this->identities->findByEmailLookupForUpdate($input->email->hash());

        if ($existing !== null && $existing['status'] !== 'pending_verification') {
            // T-01: the owner learns someone tried; the caller learns nothing.
            $this->outbox->enqueue($input->email, EmailTemplates::ACCOUNT_EXISTS, ['name' => $existing['full_name']]);

            return;
        }

        if ($existing === null) {
            $identityId = bin2hex(random_bytes(16));
            $this->identities->createPending(
                $identityId,
                bin2hex(random_bytes(16)),
                $input->email->value,
                $input->email->hash(),
                $input->fullName,
                $input->phone,
                $passwordHash,
                $this->termsVersion,
                $now,
            );
        } else {
            // T-46: the newest submission replaces a pending registration and
            // every older link stops working.
            $identityId = $existing['id'];
            $this->identities->overwritePending($identityId, $input->fullName, $input->phone, $passwordHash, $this->termsVersion, $now);
        }

        $token = self::newToken();
        $this->identities->replaceVerificationToken($identityId, hash('sha256', $token), $now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds'));
        $this->outbox->enqueue($input->email, EmailTemplates::VERIFY_EMAIL, ['name' => $input->fullName, 'token' => $token]);
    }

    /** 256 bits from the CSPRNG, base64url without padding: 43 characters (D-1). */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
