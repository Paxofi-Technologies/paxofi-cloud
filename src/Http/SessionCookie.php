<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/**
 * The customer session cookie (threat model D-6). The `__Host-` prefix makes
 * browsers refuse it unless it is Secure, host-only and Path=/, so a sibling
 * subdomain cannot plant or overwrite it. No Max-Age: it ends with the browser
 * session, and the server enforces the idle and absolute timeouts.
 */
final class SessionCookie
{
    public const string NAME = '__Host-pc_sid';

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
            throw new \InvalidArgumentException('Session ID has an unexpected format.');
        }

        return self::NAME . '=' . $sessionId . '; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    public static function clear(): string
    {
        return self::NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }
}
