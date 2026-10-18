<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\IdentityStore;
use PaxofiCloud\Application\Contracts\RateLimiter;

/**
 * Confirms an email address (SRS IAM-002; threat model T-03). The token is
 * looked up by its SHA-256 only (D-2), consumed atomically and can never be
 * used twice. Every failure looks the same to the caller.
 */
final readonly class VerifyEmail
{
    public const int PER_IP_LIMIT = 30;
    public const int WINDOW_SECONDS = 60;

    public function __construct(
        private IdentityStore $identities,
        private TransactionManager $transactions,
        private RateLimiter $limiter,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @throws VerificationTokenInvalid
     * @throws RegistrationThrottled
     */
    public function verify(#[\SensitiveParameter] mixed $token, ClientContext $client): void
    {
        $retry = $this->limiter->hit('verify-email:ip:' . hash('sha256', $client->ip), self::PER_IP_LIMIT, self::WINDOW_SECONDS);
        if ($retry > 0) {
            throw new RegistrationThrottled($retry);
        }
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new VerificationTokenInvalid();
        }

        $verified = $this->transactions->transaction(function () use ($token): ?string {
            $now = $this->clock->now();
            $identityId = $this->identities->consumeVerificationToken(hash('sha256', $token), $now);

            return $identityId !== null && $this->identities->activate($identityId, $now) ? $identityId : null;
        });
        if (!is_string($verified)) {
            throw new VerificationTokenInvalid();
        }
        $this->audit->record(AuditEventType::EMAIL_VERIFIED, AuditEvent::SUCCESS, $client, $verified);
    }
}
