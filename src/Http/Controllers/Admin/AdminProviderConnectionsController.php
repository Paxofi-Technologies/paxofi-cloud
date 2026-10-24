<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Providers\ProviderConnectionRefused;
use PaxofiCloud\Application\Providers\ProviderConnections;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** Provider connections (THREAT-MODEL-PROVIDER-CONNECTIONS.md): status out, secrets in, never back out (PC-2). */
final readonly class AdminProviderConnectionsController
{
    public function __construct(private ProviderConnections $connections)
    {
    }

    public function list(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok(['providers' => $this->connections->overview($context->staffId(), RequestClient::of($request))]);
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function submit(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $provider = $data['provider'] ?? null;
        $fields = $data['fields'] ?? null;
        $code = $data['code'] ?? null;
        if ($data === null || !is_string($provider) || !is_array($fields) || !is_string($code) || array_diff(array_keys($data), ['provider', 'fields', 'code']) !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->connections->submit($context->staffId(), $provider, $fields, $code, RequestClient::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (ProviderConnectionRefused $e) {
            return self::refused($e, $request);
        }

        // Accepted for the provider worker, which encrypts and stores it within seconds.
        return AdminJson::ok(['status' => 'pending'], 202);
    }

    private static function refused(ProviderConnectionRefused $e, HttpRequest $request): HttpResponse
    {
        $id = RequestId::of($request);

        return match ($e->reason) {
            ProviderConnectionRefused::THROTTLED => ProblemDetails::response(429, $e->reason, $id, headers: ['retry-after' => (string) max(1, $e->retryAfter)]),
            ProviderConnectionRefused::STEP_UP => ProblemDetails::response(401, $e->reason, $id),
            ProviderConnectionRefused::UNKNOWN_PROVIDER => ProblemDetails::response(404, $e->reason, $id),
            ProviderConnectionRefused::UNAVAILABLE => ProblemDetails::response(503, $e->reason, $id),
            default => ProblemDetails::response(422, $e->reason, $id, errors: [$e->field ?? 'fields' => 'invalid']),
        };
    }
}
