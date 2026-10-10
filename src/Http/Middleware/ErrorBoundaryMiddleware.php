<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Middleware;

use Paxofi\Core\Contracts\HttpHandler;
use Paxofi\Core\Contracts\HttpMiddleware;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Http\HttpException;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;
use Throwable;

/**
 * Outermost middleware. Converts every exception into an RFC 9457 response
 * and logs it with the request ID. PCF's kernel exception handler receives
 * no request, so this is where correlation happens.
 *
 * 4xx HttpException messages are written by our own code for the client and
 * are returned as `detail`. 5xx details are logged server-side only, unless
 * debug mode is on (development only).
 */
final readonly class ErrorBoundaryMiddleware implements HttpMiddleware
{
    public function __construct(private Logger $logger, private bool $debug = false)
    {
    }

    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $requestId = RequestId::of($request);
            $status = $exception instanceof HttpException ? $exception->status() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            $context = [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => parse_url($request->uri(), PHP_URL_PATH),
                'status' => $status,
                'exception' => $exception::class,
            ];

            if ($status >= 500) {
                $this->logger->error('Unhandled exception', $context + [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile() . ':' . $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);

                return ProblemDetails::response(
                    $status,
                    $status === 503 ? 'service_unavailable' : 'internal_error',
                    $requestId,
                    $this->debug ? $exception->getMessage() : null,
                );
            }

            $this->logger->info('Client error', $context);

            return ProblemDetails::response($status, 'http_' . $status, $requestId, $exception->getMessage());
        }
    }
}
