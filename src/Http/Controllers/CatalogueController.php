<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Catalogue\PublicCatalogue;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * GET /api/v1/catalogue — the plans on sale with NGN and USD prices, for the
 * website and the portal (CAT-006). Public and read-only; cacheable for five
 * minutes so Cloudflare absorbs most traffic; a per-IP limit protects the
 * origin (commerce threat model C-12).
 */
final readonly class CatalogueController implements Controller
{
    public const int IP_LIMIT_PER_MINUTE = 120;

    public function __construct(private PublicCatalogue $catalogue, private RateLimiter $limiter)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $ip = RequestClient::of($request)->ip;
        $retry = $this->limiter->hit('catalogue:ip:' . hash('sha256', $ip !== '' ? $ip : 'unknown'), self::IP_LIMIT_PER_MINUTE, 60);
        if ($retry > 0) {
            return ProblemDetails::response(429, 'too_many_requests', RequestId::of($request), headers: ['retry-after' => (string) $retry]);
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300'],
            json_encode($this->catalogue->view(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
