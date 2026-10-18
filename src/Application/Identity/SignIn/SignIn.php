<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\PasswordHasher;
use PaxofiCloud\Application\Contracts\PasswordRehashPolicy;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Contracts\SignInThrottle;
use PaxofiCloud\Application\Identity\Session\IssuedSession;
use PaxofiCloud\Application\Identity\Session\Sessions;

/**
 * Customer sign-in with email and password (SRS IAM-005, IAM-006; threat model §6.11,
 * T-07..T-12, T-18, D-3, D-7).
 */
final readonly class SignIn
{
    public const int PER_IP_LIMIT = 30;
    public const int PER_IP_WINDOW_SECONDS = 60;
    public const int ACCOUNT_ALERT_THRESHOLD = 10;

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
    ) {
    }

    /**
     * @param array<array-key, mixed> $data the decoded request body
     * @param string|null $previousSessionId the session cookie sent with the request, if any
     * @throws InvalidSignIn
     * @throws SignInThrottled
     * @throws InvalidCredentials
     * @throws EmailNotVerified
     * @throws AccountUnavailable
     */
    public function signIn(
        #[\SensitiveParameter] array $data,
        string $clientIp,
        string $userAgent,
        #[\SensitiveParameter] ?string $previousSessionId,
    ): IssuedSession {
        $input = SignInInput::fromArray($data);
        $lookup = $input->email->hash();

        // T-11: both limits run before the hash.
        $retry = $this->limiter->hit('signin:ip:' . hash('sha256', $clientIp), self::PER_IP_LIMIT, self::PER_IP_WINDOW_SECONDS);
        $pair = hash('sha256', $lookup . '|' . self::network($clientIp));
        $retry = max($retry, $this->throttle->retryAfter($pair));
        if ($retry > 0) {
            throw new SignInThrottled($retry);
        }

        $identity = $this->identities->findForSignIn($lookup);
        $matches = $this->hasher->verify($input->password, $identity['password_hash'] ?? self::DUMMY_HASH);
        if ($identity === null || !$matches) {
            $this->throttle->recordFailure($pair);
            if ($identity !== null && $this->throttle->recordAccountFailure($lookup) > self::ACCOUNT_ALERT_THRESHOLD) {
                // T-08 counter (b). Turnstile and the owner email follow in their own PRs.
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
