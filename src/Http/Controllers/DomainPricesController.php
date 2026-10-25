<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Domains\DomainPriceList;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * GET /api/v1/domains/prices?q=&page=&category= — the public domain price list
 * (THREAT-MODEL-DOMAIN-SEARCH.md DS-11). Public, read-only, cacheable for
 * five minutes. Only selling prices, featured flags and labels come out.
 */
final readonly class DomainPricesController implements Controller
{
    public function __construct(private DomainPriceList $prices)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $query = $request->query();
        $search = $query['q'] ?? '';
        $page = $query['page'] ?? '1';
        $category = $query['category'] ?? '';
        if (!is_string($search) || strlen($search) > 63 || !is_string($page) || preg_match('/^[1-9]\d{0,3}$/', $page) !== 1 || !is_string($category) || strlen($category) > 16) {
            return ProblemDetails::response(400, DomainSearchRefused::INVALID_NAME, RequestId::of($request));
        }
        try {
            $result = $this->prices->view(RequestClient::of($request)->ip, $search, (int) $page, $category);
        } catch (DomainSearchRefused $e) {
            return $e->reason === DomainSearchRefused::TOO_MANY
                ? ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter])
                : ProblemDetails::response(422, $e->reason, RequestId::of($request));
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300'],
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
