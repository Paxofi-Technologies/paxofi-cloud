<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * POST /api/v1/domains/check {"domains": [...]} queues up to 6 names for the
 * registrar's own availability and premium check (DS-13) and answers 202
 * with a job ID; GET /api/v1/domains/check?id= returns pending, done (with
 * results) or expired. Public, no-store; the registrar key stays in the
 * provider worker.
 */
final readonly class DomainCheckController implements Controller
{
    public function __construct(private RegistrarCheck $check)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        try {
            if ($request->method() === 'POST') {
                $body = JsonBody::object($request);
                $domains = $body['domains'] ?? null;
                if ($body === null || array_keys($body) !== ['domains'] || !is_array($domains)) {
                    return ProblemDetails::response(400, DomainSearchRefused::INVALID_NAME, RequestId::of($request));
                }

                return self::json(202, ['id' => $this->check->request(RequestClient::of($request)->ip, $domains)]);
            }
            $id = $request->query()['id'] ?? null;
            if (!is_string($id)) {
                return ProblemDetails::response(400, DomainSearchRefused::INVALID_NAME, RequestId::of($request));
            }

            return self::json(200, $this->check->result($id));
        } catch (DomainSearchRefused $e) {
            return $e->reason === DomainSearchRefused::TOO_MANY
                ? ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter])
                : ProblemDetails::response(422, $e->reason, RequestId::of($request));
        }
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new Response($status, ['content-type' => 'application/json', 'cache-control' => 'no-store'], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
