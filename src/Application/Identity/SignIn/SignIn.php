<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\PasswordHasher;
use PaxofiCloud\Application\Contracts\PasswordRehashPolicy;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Contracts\SignInThrottle;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Challenge\ChallengeUnavailable;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\MfaPending;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Session\IssuedSession;
use PaxofiCloud\Application\Identity\Session\Sessions;

/**
 * Customer sign-in with email and password, then TOTP when it is on (SRS
 * IAM-005..007; threat model §6.11, §6.13,
 * T-07..T-12, T-18, D-3, D-7).
 */
final readonly class SignIn
{
    public const int PER_IP_LIMIT = 30;
    public const int PER_IP_WINDOW_SECONDS = 60;
    public const int ACCOUNT_ALERT_THRESHOLD = 10;
    /** §6.12: a challenge is required from this many failures for the pair. */
    public const int CHALLENGE_AFTER_PAIR_FAILURES = 3;

    /**
     * D-7: verified against when the email is unknown so both paths cost one
     * Argon2id verification with the production parameters. The input that
     * produced it was random and discarded; nothing can sign in with it.
     */
    public const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$YlFFMGdoRlJmQURqQkxicQ$ugGIshkDsN9reF2CEB/2pLTqOmD+vN0TmIPbz2yujVY';

    public function __construct(
        private SignInStore $identities,
        private PasswordHasher $hasher,
        private PasswordRehashPolicy $rehash,
        private RateLimiter $limiter,
        private SignInThrottle $throttle,
        private Sessions $sessions,
        private Logger $logger,
        private ChallengeGate $challenge,
        private MfaSettings $mfa,
        private CompleteMfaSignIn $mfaSignIn,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data the decoded request body
     * @param string|null $previousSessionId the session cookie sent with the request, if any
     * @throws InvalidSignIn
     * @throws SignInThrottled
     * @throws ChallengeRequired
     * @throws ChallengeUnavailable
     * @throws InvalidCredentials
     * @throws EmailNotVerified
     * @throws AccountUnavailable
     */
    public function signIn(
        #[\SensitiveParameter] array $data,
        string $clientIp,
        string $userAgent,
        #[\SensitiveParameter] ?string $previousSessionId,
    ): IssuedSession|MfaPending {
        $input = SignInInput::fromArray($data);
        $lookup = $input->email->hash();

        // T-11: both limits run before the hash.
        $retry = $this->limiter->hit('signin:ip:' . hash('sha256', $clientIp), self::PER_IP_LIMIT, self::PER_IP_WINDOW_SECONDS);
        $pair = hash('sha256', $lookup . '|' . self::network($clientIp));
        $retry = max($retry, $this->throttle->retryAfter($pair));
        if ($retry > 0) {
            throw new SignInThrottled($retry);
        }

        // §6.12: risky attempts must pass a challenge before any hash. Counter (b)
        // counts every address, so this never depends on the account existing.
        $failures = $this->throttle->failures($pair, $lookup);
        if ($failures['pair'] >= self::CHALLENGE_AFTER_PAIR_FAILURES || $failures['account'] > self::ACCOUNT_ALERT_THRESHOLD) {
            $this->challenge->assertHuman(ChallengeGate::token($data), ChallengeGate::SIGN_IN, $clientIp);
        }

        $identity = $this->identities->findForSignIn($lookup);
        $matches = $this->hasher->verify($input->password, $identity['password_hash'] ?? self::DUMMY_HASH);
        if ($identity === null || !$matches) {
            $this->throttle->recordFailure($pair);
            $accountFailures = $this->throttle->recordAccountFailure($lookup);
            if ($identity !== null && $accountFailures > self::ACCOUNT_ALERT_THRESHOLD) {
                // T-07 counter (b): challenged from now on (§6.12); owner email follows with notifications.
                $this->logger->warning('auth.sign_in.account_failures_high', ['identity_id' => $identity['id']]);
            }

            throw new InvalidCredentials();
        }

        $this->throttle->clear($pair);

        if ($identity['status'] === 'pending_verification') {
            throw new EmailNotVerified();
        }
        if ($identity['status'] !== 'active') {
            throw new AccountUnavailable();
        }

        if ($this->rehash->needsRehash($identity['password_hash'])) {
            $this->identities->replacePasswordHash($identity['id'], $this->hasher->hash($input->password));
        }

        // T-18: a new ID every time; whatever the browser held before is destroyed.
        $this->sessions->end($previousSessionId);

        // §6.13: with TOTP on, the password only earns a short ticket for the second step.
        if ($this->mfa->isEnabled($identity['id'])) {
            return $this->mfaSignIn->start($identity['id'], $identity['account_id']);
        }

        return $this->sessions->issue($identity['id'], $identity['account_id'], $clientIp, $userAgent);
    }

    /** IPv4 /24 or IPv6 /64: the network a household or office usually shares (T-08). */
    public static function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'unknown';
        }

        return bin2hex(strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 8));
    }
}
