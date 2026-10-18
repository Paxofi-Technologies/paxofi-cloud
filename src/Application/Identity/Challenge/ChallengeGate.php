<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Challenge;

use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Contracts\HumanVerification;

/**
 * Turns a challenge verification into an allow or a refusal (threat model
 * §6.12). Use cases call it after their cheap rate limits and before any
 * expensive or outward work.
 */
final readonly class ChallengeGate
{
    public const string FIELD = 'turnstile_token';
    public const string REGISTER = 'register';
    public const string RESEND = 'resend';
    public const string SIGN_IN = 'sign_in';

    public function __construct(private HumanVerification $verification)
    {
    }

    /**
     * @throws ChallengeRequired
     * @throws ChallengeUnavailable
     */
    public function assertHuman(#[\SensitiveParameter] ?string $token, string $action, string $clientIp): void
    {
        if (!$this->verification->enabled()) {
            return;
        }

        match ($this->verification->verify($token, $action, $clientIp)) {
            HumanCheck::Passed => null,
            HumanCheck::Failed => throw new ChallengeRequired(),
            HumanCheck::Unavailable => throw new ChallengeUnavailable(),
        };
    }

    /**
     * The token posted with a form, or null when absent or not plausibly a token.
     * A malformed token simply fails the challenge; it is never echoed back.
     *
     * @param array<array-key, mixed> $data
     */
    public static function token(#[\SensitiveParameter] array $data): ?string
    {
        $token = $data[self::FIELD] ?? null;

        return is_string($token) && preg_match('/^[\x21-\x7E]{1,2048}$/', $token) === 1 ? $token : null;
    }
}
