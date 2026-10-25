<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Registrar\SpaceshipPriceCheckAdmin;
use PaxofiCloud\Application\Registrar\SyncRequestRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * The Spaceship price check (THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7): the
 * newest result, and a request for the provider worker to run one. The
 * request body is an empty object; nothing from the request reaches Spaceship.
 */
final readonly class AdminSpaceshipPriceCheckController
{
    public function __construct(private SpaceshipPriceCheckAdmin $checks)
    {
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->checks->overview($context->staffId(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function run(HttpRequest $request, AdminContext $context): HttpResponse
    {
        if (trim($request->body()) !== '{}') {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->checks->requestCheck($context->staffId(), RequestClient::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (SyncRequestRefused $e) {
            return $e->reason === SyncRequestRefused::TOO_SOON
                ? ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter])
                : ProblemDetails::response(409, $e->reason, RequestId::of($request));
        }

        // Queued for the provider worker, which picks it up within seconds.
        return AdminJson::ok(['status' => 'queued'], 202);
    }
}
