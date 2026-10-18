<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Middleware;

use Paxofi\Core\Contracts\HttpHandler;
use Paxofi\Core\Contracts\HttpMiddleware;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;

/**
 * The admin API answers only on the admin host (threat model D-6, §6.18):
 * without ADMIN_URL, or on any other Host, `/admin-api/` is a plain 404.
 * Writes also need Origin = the admin origin and a JSON body (as T-48).
 */
final readonly class AdminApiGuardMiddleware implements HttpMiddleware
{
    private const string PREFIX = '/admin-api/';

    private ?string $origin;
    private ?string $authority;

    public function __construct(string $adminUrl)
    {
        $parts = $adminUrl === '' ? false : parse_url($adminUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            $this->origin = null;
            $this->authority = null;

            return;
        }
        $this->authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->origin = strtolower($parts['scheme']) . '://' . $this->authority;
    }

    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, self::PREFIX)) {
            return $handler->handle($request);
        }
        if ($this->authority === null || strtolower((string) $request->header('host')) !== $this->authority) {
            return ProblemDetails::response(404, 'not_found', RequestId::of($request));
        }
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            $origin = $request->header('origin');
            if ($origin === null || strtolower($origin) !== $this->origin) {
                return ProblemDetails::response(403, 'origin_not_allowed', RequestId::of($request));
            }
            $type = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
            if ($type !== 'application/json') {
                return ProblemDetails::response(415, 'json_required', RequestId::of($request));
            }
        }

        return $handler->handle($request);
    }
}
