<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Middleware;

use Paxofi\Core\Contracts\HttpHandler;
use Paxofi\Core\Contracts\HttpMiddleware;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\RequestId;

/** Rejects bodies over the configured limit with 413 (SRS API-002). */
final class BodySizeLimitMiddleware implements HttpMiddleware
{
    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        if (($request->attributes()[RequestFactory::BODY_TOO_LARGE] ?? false) === true) {
            return ProblemDetails::response(413, 'body_too_large', RequestId::of($request));
        }

        return $handler->handle($request);
    }
}
