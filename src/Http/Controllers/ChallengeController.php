<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;

/**
 * GET /api/v1/auth/challenge: the PUBLIC Turnstile site key the forms render
 * the widget with, or null when no challenge is configured (§6.12). The secret
 * key never leaves the server.
 */
final readonly class ChallengeController implements Controller
{
    public function __construct(private ?string $siteKey)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode(['turnstile_site_key' => $this->siteKey], JSON_THROW_ON_ERROR),
        );
    }
}
