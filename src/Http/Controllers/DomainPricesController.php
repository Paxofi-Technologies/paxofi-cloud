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
 * (THREAT-MODEL-DOMAIN-SEARCH.md DS-11). Public and read-only; browsers
 * revalidate on every load (ETag), so staff changes show at once. Only
 * selling prices, featured flags and labels come out.
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

        $body = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Staff changes (featured, labels, prices) must show on the next load, so
        // browsers revalidate every time; an unchanged list costs only a 304.
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $headers = ['cache-control' => 'no-cache', 'etag' => $etag];
        if (self::matches($request->header('if-none-match'), $etag)) {
            return new Response(304, $headers, '');
        }

        return new Response(200, ['content-type' => 'application/json', ...$headers], $body);
    }

    private static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || strlen($ifNoneMatch) > 512) {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (hash_equals($etag, preg_replace('/^W\//', '', trim($candidate)) ?? '')) {
                return true;
            }
        }

        return false;
    }
}
