<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/**
 * The staff session cookie (threat model D-6, §6.18): `__Host-` prefix
 * (Secure, host-only, Path=/), SameSite=Strict, no Max-Age. Never the customer
 * cookie, and never read on customer routes.
 */
final class StaffSessionCookie
{
    public const string NAME = '__Host-pc_staff';

    public static function read(HttpRequest $request): ?string
    {
        $header = $request->header('cookie');
        if ($header === null) {
            return null;
        }
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === self::NAME) {
                return preg_match('/^[A-Za-z0-9_-]{43}$/', $parts[1]) === 1 ? $parts[1] : null;
            }
        }

        return null;
    }

    public static function issue(#[\SensitiveParameter] string $sessionId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Staff session ID has an unexpected format.');
        }

        return self::NAME . '=' . $sessionId . '; Path=/; Secure; HttpOnly; SameSite=Strict';
    }

    public static function clear(): string
    {
        return self::NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict';
    }
}
