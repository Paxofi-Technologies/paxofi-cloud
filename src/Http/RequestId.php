<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

final class RequestId
{
    /** SRS API-008: client-supplied IDs are accepted only in this shape. */
    public const string PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function of(HttpRequest $request): ?string
    {
        $id = $request->attributes()['_request_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
