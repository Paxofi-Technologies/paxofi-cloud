<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/**
 * The second-step ticket cookie (threat model §6.13): HttpOnly so page script
 * never sees it, `__Host-` so no subdomain can plant one, and gone after
 * 5 minutes like the ticket itself.
 */
final class MfaCookie
{
    public const string NAME = '__Host-pc_mfa';

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
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket) !== 1) {
            throw new \InvalidArgumentException('MFA ticket has an unexpected format.');
        }

        return self::NAME . '=' . $ticket . '; Path=/; Max-Age=' . $maxAge . '; Secure; HttpOnly; SameSite=Strict';
    }

    public static function clear(): string
    {
        return self::NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict';
    }
}
