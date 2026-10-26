<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Payments;

/**
 * Tells test keys from live keys by their documented prefixes (PY-4,
 * PT-09). A public key, a key of the other gateway or anything unrecognised
 * has no mode and is never accepted.
 */
final class GatewayKeyGuard
{
    private const array PATTERNS = [
        'paystack' => ['test' => '/^sk_test_[A-Za-z0-9]{16,128}$/D', 'live' => '/^sk_live_[A-Za-z0-9]{16,128}$/D'],
        'flutterwave' => ['test' => '/^FLWSECK_TEST-[A-Za-z0-9]{16,128}-X$/D', 'live' => '/^FLWSECK-[A-Za-z0-9]{16,128}-X$/D'],
    ];

    public static function modeOf(Gateway $gateway, #[\SensitiveParameter] string $secretKey): ?GatewayMode
    {
        foreach (self::PATTERNS[$gateway->value] as $mode => $pattern) {
            if (preg_match($pattern, $secretKey) === 1) {
                return GatewayMode::from($mode);
            }
        }

        return null;
    }

    public static function allows(Gateway $gateway, #[\SensitiveParameter] string $secretKey, GatewayMode $required): bool
    {
        return self::modeOf($gateway, $secretKey) === $required;
    }
}
