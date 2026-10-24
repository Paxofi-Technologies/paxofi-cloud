<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Registrar\DomainCostAdmin;
use PaxofiCloud\Application\Registrar\SyncRequestRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** Domain costs (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13): internal cost list and "Sync now". */
final readonly class AdminDomainCostsController
{
    public function __construct(private DomainCostAdmin $costs)
    {
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->costs->overview($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    /** Takes no input: the provider is fixed on the server. */
    public function sync(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            $this->costs->requestSync($context->staffId(), RequestClient::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (SyncRequestRefused $e) {
            return ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]);
        }

        // Queued for the provider worker, which picks it up within seconds.
        return AdminJson::ok(['status' => 'queued'], 202);
    }
}
