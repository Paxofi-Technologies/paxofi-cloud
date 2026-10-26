<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Billing\TaxRateChangeRefused;
use PaxofiCloud\Application\Billing\TaxRatesAdmin;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** The Tax rates page (commerce threat model CD-28). */
final readonly class AdminTaxRatesController
{
    public function __construct(private TaxRatesAdmin $rates)
    {
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->rates->overview($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function change(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->rates->change($context->staffId(), RequestClient::of($request), $data);
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (TaxRateChangeRefused $e) {
            return match ($e->reason) {
                TaxRateChangeRefused::THROTTLED => ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]),
                TaxRateChangeRefused::STEP_UP => ProblemDetails::response(403, $e->reason, RequestId::of($request)),
                TaxRateChangeRefused::DUPLICATE_START => ProblemDetails::response(409, $e->reason, RequestId::of($request)),
                default => ProblemDetails::response(422, $e->reason, RequestId::of($request)),
            };
        }

        return AdminJson::ok(['status' => 'saved']);
    }
}
