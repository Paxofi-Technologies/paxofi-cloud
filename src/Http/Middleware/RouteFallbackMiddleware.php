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
 * PCF's router answers unknown paths and wrong methods with plain-text
 * 404/405. Rewrite them as problem details so every API error has one shape.
 */
final class RouteFallbackMiddleware implements HttpMiddleware
{
    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        $response = $handler->handle($request);
        $status = $response->status();
        if (($status !== 404 && $status !== 405) || $response->header('content-type') !== null) {
            return $response;
        }

        $headers = [];
        $allow = $response->header('allow');
        if ($allow !== null) {
            $headers['allow'] = $allow;
        }

        return ProblemDetails::response(
            $status,
            $status === 404 ? 'route_not_found' : 'method_not_allowed',
            RequestId::of($request),
            headers: $headers,
        );
    }
}
