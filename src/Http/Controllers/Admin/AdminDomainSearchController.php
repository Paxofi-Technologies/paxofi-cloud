<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Domains\DomainSearchAdmin;
use PaxofiCloud\Application\Domains\DomainSearchChangeRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** The Domain search page (THREAT-MODEL-DOMAIN-SEARCH.md DS-21 to DS-23). */
final readonly class AdminDomainSearchController
{
    public function __construct(private DomainSearchAdmin $admin)
    {
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->admin->overview($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function saveSettings(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return $this->change($request, fn (array $data) => $this->admin->saveSettings($context->staffId(), RequestClient::of($request), $data));
    }

    public function saveLookup(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return $this->change($request, fn (array $data) => $this->admin->saveLookup($context->staffId(), RequestClient::of($request), $data));
    }

    /** @param \Closure(array<array-key, mixed>): void $change */
    private function change(HttpRequest $request, \Closure $change): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $change($data);
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (DomainSearchChangeRefused $e) {
            return match ($e->reason) {
                DomainSearchChangeRefused::THROTTLED => ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]),
                DomainSearchChangeRefused::STEP_UP => ProblemDetails::response(403, $e->reason, RequestId::of($request)),
                default => ProblemDetails::response(422, $e->reason, RequestId::of($request)),
            };
        }

        return AdminJson::ok(['status' => 'saved']);
    }
}
