<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Registrar\DomainCostAdmin;
use PaxofiCloud\Application\Registrar\SyncRequestRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * Domain costs (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13; NC-9): the
 * internal cost list and "Sync now", per registrar. The registrar is chosen
 * from a fixed server-side list; anything else is refused.
 */
final readonly class AdminDomainCostsController
{
    /** @param non-empty-array<string, DomainCostAdmin> $costs provider => admin service, first is the default */
    public function __construct(private array $costs)
    {
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $provider = $request->query()['provider'] ?? array_key_first($this->costs);
        $costs = is_string($provider) ? ($this->costs[$provider] ?? null) : null;
        if ($costs === null) {
            return ProblemDetails::response(404, 'unknown_provider', RequestId::of($request));
        }
        try {
            return AdminJson::ok(['providers' => array_keys($this->costs)] + $costs->overview($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    /** Takes only the registrar name, checked against the fixed list. */
    public function sync(HttpRequest $request, AdminContext $context): HttpResponse
    {
        // An empty object (the page before this change) means the default registrar.
        $data = JsonBody::object($request) ?? (trim($request->body()) === '{}' ? [] : null);
        $provider = $data === null ? null : ($data['provider'] ?? array_key_first($this->costs));
        if ($data === null || !is_string($provider) || array_diff(array_keys($data), ['provider']) !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        $costs = $this->costs[$provider] ?? null;
        if ($costs === null) {
            return ProblemDetails::response(404, 'unknown_provider', RequestId::of($request));
        }
        try {
            $costs->requestSync($context->staffId(), RequestClient::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (SyncRequestRefused $e) {
            return ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]);
        }

        // Queued for the provider worker, which picks it up within seconds.
        return AdminJson::ok(['status' => 'queued'], 202);
    }
}
