<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Kernel;
use Paxofi\Core\Http\Router;
use PaxofiCloud\Infrastructure\Container\Resolve;

/** Wires PCF's HTTP kernel with PaxofiCloud's router, middleware and handler. */
final readonly class HttpRuntime
{
    private Kernel $kernel;

    public function __construct(Application $application)
    {
        $container = $application->container();
        $this->kernel = new Kernel(
            $application,
            Resolve::get($container, Router::class),
            Resolve::get($container, MiddlewareStack::class)->middleware,
            Resolve::get($container, ProblemDetailsExceptionHandler::class),
        );
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        return $this->kernel->handle($request);
    }
}
