<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/**
 * The staff sign-in ticket between the password and the last code (§6.20):
 * `__Host-` (Secure, host-only, Path=/), HttpOnly, SameSite=Strict, and gone
 * when the ticket would expire anyway.
 */
final class StaffStepCookie
{
    public const string NAME = '__Host-pc_staff_step';

    public static function read(HttpRequest $request): ?string
    {
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === self::NAME) {
                return preg_match('/^[A-Za-z0-9_-]{43}$/', $parts[1]) === 1 ? $parts[1] : null;
            }
        }

        return null;
    }

    public static function issue(#[\SensitiveParameter] string $ticket, int $maxAge): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket) !== 1 || $maxAge < 1) {
            throw new \InvalidArgumentException('Staff sign-in ticket has an unexpected format.');
        }

        return self::NAME . '=' . $ticket . '; Path=/; Max-Age=' . $maxAge . '; Secure; HttpOnly; SameSite=Strict';
    }

    public static function clear(): string
    {
        return self::NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict';
    }
}
