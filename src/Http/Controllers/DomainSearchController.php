<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Domains\DomainSearch;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * GET /api/v1/domains/search?name=&tld= — the customer domain search
 * (THREAT-MODEL-DOMAIN-SEARCH.md DS-10). Public, read-only, never cached.
 * Only selling prices, availability, featured flags and labels come out.
 */
final readonly class DomainSearchController implements Controller
{
    public function __construct(private DomainSearch $search)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $query = $request->query();
        $name = $query['name'] ?? null;
        $tld = $query['tld'] ?? null;
        if (!is_string($name) || strlen($name) > 253 || ($tld !== null && (!is_string($tld) || strlen($tld) > 63))) {
            return ProblemDetails::response(400, DomainSearchRefused::INVALID_NAME, RequestId::of($request));
        }
        try {
            $result = $this->search->search(RequestClient::of($request)->ip, $name, $tld === '' ? null : $tld);
        } catch (DomainSearchRefused $e) {
            return $e->reason === DomainSearchRefused::TOO_MANY
                ? ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter])
                : ProblemDetails::response(422, $e->reason, RequestId::of($request));
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
