<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\ExceptionHandler;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Logger;
use Throwable;

/**
 * Last-resort handler for the PCF kernel. In practice ErrorBoundaryMiddleware
 * catches everything first; this exists so nothing ever falls back to PCF's
 * plain-text handler.
 */
final readonly class ProblemDetailsExceptionHandler implements ExceptionHandler
{
    public function __construct(private Logger $logger)
    {
    }

    public function handle(Throwable $exception): HttpResponse
    {
        $this->logger->error('Unhandled exception outside middleware', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        return ProblemDetails::response(500, 'internal_error');
    }
}
