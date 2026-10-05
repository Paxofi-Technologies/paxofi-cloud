<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Closure;
use Paxofi\Core\Contracts\HttpHandler;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;

final readonly class CallableHandler implements HttpHandler
{
    /** @param Closure(HttpRequest): HttpResponse $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        return ($this->handler)($request);
    }
}
