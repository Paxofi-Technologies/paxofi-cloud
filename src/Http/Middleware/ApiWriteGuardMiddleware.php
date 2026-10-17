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
 * Cross-origin protection for state-changing API calls (threat model T-21,
 * T-48). A write to /api must:
 *  - carry an Origin header equal to APP_URL's origin, and
 *  - be JSON (`application/json`). Browsers cannot send that cross-origin
 *    without a CORS preflight, which this API never approves.
 * Sessions will add the T-21 synchroniser token on top.
 */
final readonly class ApiWriteGuardMiddleware implements HttpMiddleware
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private string $origin;

    public function __construct(string $appUrl)
    {
        $parts = parse_url($appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('APP_URL must be an absolute URL.');
        }
        $this->origin = strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (in_array($request->method(), self::SAFE_METHODS, true) || !is_string($path) || !str_starts_with($path, '/api/')) {
            return $handler->handle($request);
        }

        $origin = $request->header('origin');
        if ($origin === null || strtolower($origin) !== $this->origin) {
            return ProblemDetails::response(403, 'origin_not_allowed', RequestId::of($request));
        }
        $type = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
        if ($type !== 'application/json') {
            return ProblemDetails::response(415, 'json_required', RequestId::of($request));
        }

        return $handler->handle($request);
    }
}
