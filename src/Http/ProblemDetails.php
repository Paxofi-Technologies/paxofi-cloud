<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;

/**
 * RFC 9457 problem-details responses. Clients get a stable machine-readable
 * `code` and the request ID to quote to support; internal details never leave
 * the server.
 */
final class ProblemDetails
{
    private const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, string> $headers
     */
    public static function response(
        int $status,
        string $code,
        ?string $requestId = null,
        ?string $detail = null,
        array $headers = [],
    ): HttpResponse {
        $body = [
            'type' => 'about:blank',
            'title' => self::TITLES[$status] ?? ($status >= 500 ? 'Server Error' : 'Client Error'),
            'status' => $status,
            'code' => $code,
        ];
        if ($detail !== null && $detail !== '') {
            $body['detail'] = $detail;
        }
        if ($requestId !== null && $requestId !== '') {
            $body['request_id'] = $requestId;
        }

        return new Response(
            $status,
            ['content-type' => 'application/problem+json'] + $headers,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
