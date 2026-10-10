<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Http\Request;

/**
 * Builds a PCF request from PHP's SAPI globals (PCF has no fromGlobals()).
 *
 * - Header names are normalised to lower-case.
 * - An invalid client X-Request-Id is dropped so PCF generates a fresh one;
 *   PCF otherwise echoes it unvalidated (log/response injection, T-42).
 * - At most max+1 body bytes are read; an oversized body is flagged for
 *   BodySizeLimitMiddleware instead of being buffered in full.
 * - Query parameters keep only strings and lists of strings; nested arrays
 *   (`?a[b][c]=…`) are dropped rather than reaching application code.
 */
final readonly class RequestFactory
{
    public const string BODY_TOO_LARGE = '_body_too_large';

    public function __construct(private int $maxBodyBytes = 1_048_576)
    {
    }

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $query
     * @param resource|null $input
     */
    public function fromGlobals(array $server, array $query, $input = null): HttpRequest
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        if (isset($headers['x-request-id']) && !RequestId::isValid($headers['x-request-id'])) {
            unset($headers['x-request-id']);
        }

        $body = '';
        $tooLarge = false;
        if ($input !== null) {
            $read = stream_get_contents($input, $this->maxBodyBytes + 1);
            $body = is_string($read) ? $read : '';
            if (strlen($body) > $this->maxBodyBytes) {
                $body = '';
                $tooLarge = true;
            }
        }

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $uri = $server['REQUEST_URI'] ?? '/';
        $request = new Request(
            is_string($method) ? strtoupper($method) : 'GET',
            is_string($uri) ? $uri : '/',
            $headers,
            $body,
            self::sanitiseQuery($query),
        );

        return $tooLarge ? $request->withAttribute(self::BODY_TOO_LARGE, true) : $request;
    }

    /**
     * @param array<array-key, mixed> $query
     * @return array<string, string|list<string>>
     */
    private static function sanitiseQuery(array $query): array
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value)) {
                $clean[$key] = $value;
            } elseif (is_array($value) && array_is_list($value) && $value === array_filter($value, is_string(...))) {
                /** @var list<string> $value */
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
