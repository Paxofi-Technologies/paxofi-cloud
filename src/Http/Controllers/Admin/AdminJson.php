<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;

/** JSON helpers for the admin API: every response is no-store (§6.18). */
final class AdminJson
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string|list<string>> $headers
     */
    public static function ok(array $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new Response($status, ['content-type' => 'application/json', 'cache-control' => 'no-store'] + $headers, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string $key, int $max = 256): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }

    /**
     * A PublicKeyCredential the browser sent as a JSON object, re-encoded for the verifier.
     *
     * @param array<array-key, mixed> $data
     */
    public static function credential(array $data): ?string
    {
        $value = $data['credential'] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }

    private function __construct()
    {
    }
}
