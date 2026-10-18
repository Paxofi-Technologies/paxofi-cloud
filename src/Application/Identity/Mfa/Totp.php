<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/**
 * RFC 6238 time-based one-time passwords (threat model D-4): HMAC-SHA1 from
 * PHP's hash extension, 6 digits, 30-second steps, ±1 step of clock drift.
 * Only the protocol's truncation step is written here; the MAC is native.
 */
final class Totp
{
    public const int SECRET_BYTES = 20;
    public const int DIGITS = 6;
    public const int PERIOD = 30;
    public const int DRIFT_STEPS = 1;

    public static function newSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    public static function step(\DateTimeImmutable $at): int
    {
        return intdiv($at->getTimestamp(), self::PERIOD);
    }

    public static function code(#[\SensitiveParameter] string $secret, int $step, int $digits = self::DIGITS): string
    {
        $mac = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($mac[19]) & 0x0f;
        $value = ((ord($mac[$offset]) & 0x7f) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step the code matches, or null. Steps at or before `$lastUsedStep`
     * are never accepted, so a code works only once (T-13).
     */
    public static function match(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, \DateTimeImmutable $now, ?int $lastUsedStep): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $current = self::step($now);
        $matched = null;
        // Check every candidate step so the time taken does not reveal which one matched.
        for ($step = $current - self::DRIFT_STEPS; $step <= $current + self::DRIFT_STEPS; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    public static function uri(#[\SensitiveParameter] string $secret, string $accountLabel, string $issuer = 'PaxofiCloud'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            Base32::encode($secret),
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }
}
