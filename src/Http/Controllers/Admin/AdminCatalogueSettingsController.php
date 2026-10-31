<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Extensions\CatalogueSettingsTransfer;
use PaxofiCloud\Application\Extensions\ExtensionChangeRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** Catalogue settings export and import (THREAT-MODEL-DOMAIN-PRICING.md DX-15). */
final readonly class AdminCatalogueSettingsController
{
    public function __construct(private CatalogueSettingsTransfer $transfer)
    {
    }

    public function export(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->transfer->export($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function import(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->transfer->import($context->staffId(), RequestClient::of($request), $data));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (ExtensionChangeRefused $e) {
            return match ($e->reason) {
                ExtensionChangeRefused::THROTTLED => ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]),
                ExtensionChangeRefused::STEP_UP => ProblemDetails::response(403, $e->reason, RequestId::of($request)),
                default => ProblemDetails::response(422, $e->reason, RequestId::of($request)),
            };
        }
    }
}
