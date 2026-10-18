<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/**
 * The long-lived device cookie (threat model T-55, §6.15). It lets the
 * server tell a known browser from a new one so the right sign-in email is
 * sent; it grants nothing and never skips a check. `__Host-` keeps sibling
 * subdomains from planting it; 400 days is the longest browsers keep a cookie.
 */
final class DeviceCookie
{
    public const string NAME = '__Host-pc_dev';
    public const int MAX_AGE_SECONDS = 34_560_000;

    /** The browser's device token, or a new one when it has none (or a malformed one). */
    public static function readOrCreate(HttpRequest $request): string
    {
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === self::NAME && preg_match('/^[A-Za-z0-9_-]{43}$/', $parts[1]) === 1) {
                return $parts[1];
            }
        }

        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Re-sent on every sign-in so a device in regular use stays known. */
    public static function issue(#[\SensitiveParameter] string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new \InvalidArgumentException('Device token has an unexpected format.');
        }

        return self::NAME . '=' . $token . '; Path=/; Max-Age=' . self::MAX_AGE_SECONDS . '; Secure; HttpOnly; SameSite=Lax';
    }
}
