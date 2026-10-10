<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;

/** GET /health/live — the process is up. No dependencies are touched (SRS OPS-005). */
final class LivenessController implements Controller
{
    public function __invoke(HttpRequest $request): HttpResponse
    {
        return new Response(200, ['content-type' => 'application/json'], '{"status":"ok"}');
    }
}
