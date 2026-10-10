<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpMiddleware;

/** Ordered HTTP middleware, outermost first. */
final readonly class MiddlewareStack
{
    /** @var list<HttpMiddleware> */
    public array $middleware;

    public function __construct(HttpMiddleware ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}
