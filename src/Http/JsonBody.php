<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;

/** Decodes a request body that must be one JSON object (depth-limited). */
final class JsonBody
{
    /** @return array<array-key, mixed>|null null when the body is not a JSON object */
    public static function object(HttpRequest $request): ?array
    {
        try {
            $data = json_decode($request->body(), true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    public static function clientIp(HttpRequest $request): string
    {
        $ip = $request->attributes()[RequestFactory::CLIENT_IP] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
