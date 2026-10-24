<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Staff\Session\StaffSession;
use PaxofiCloud\Application\Staff\Session\StaffSessionControl;
use PaxofiCloud\Application\Staff\Session\StaffSessionRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** Staff session list, sign-out and forced sign-out (identity threat model §6.25). */
final readonly class AdminSessionsController
{
    public function __construct(private StaffSessionControl $control)
    {
    }

    public function list(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return AdminJson::ok(['sessions' => $this->control->list(self::session($context))]);
    }

    public function revoke(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $id = $data['session'] ?? null;
        $code = $data['code'] ?? null;
        if ($data === null || !is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1 || !is_string($code) || array_diff(array_keys($data), ['session', 'code']) !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->control->revoke(self::session($context), $id, $code, RequestClient::of($request));
        } catch (StaffSessionRefused $e) {
            return self::refused($e, $request);
        }

        return new Response(204, ['cache-control' => 'no-store'], '');
    }

    public function revokeOthers(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $code = $data['code'] ?? null;
        if ($data === null || !is_string($code) || array_diff(array_keys($data), ['code']) !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $ended = $this->control->revokeOthers(self::session($context), $code, RequestClient::of($request));
        } catch (StaffSessionRefused $e) {
            return self::refused($e, $request);
        }

        return AdminJson::ok(['ended' => $ended]);
    }

    /** T-58: a staff manager signs another member out everywhere. */
    public function endForMember(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $staffId = $data['staff_id'] ?? null;
        $code = $data['code'] ?? null;
        if ($data === null || !is_string($staffId) || !is_string($code) || array_diff(array_keys($data), ['staff_id', 'code']) !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $ended = $this->control->endAllFor($context->staffId(), $staffId, $code, RequestClient::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (StaffSessionRefused $e) {
            return self::refused($e, $request);
        }

        return AdminJson::ok(['ended' => $ended]);
    }

    private static function session(AdminContext $context): StaffSession
    {
        return $context->session ?? throw new \LogicException('No staff session on a signed-in admin route.');
    }

    private static function refused(StaffSessionRefused $e, HttpRequest $request): HttpResponse
    {
        $id = RequestId::of($request);

        return match ($e->reason) {
            StaffSessionRefused::THROTTLED => ProblemDetails::response(429, $e->reason, $id, headers: ['retry-after' => (string) max(1, $e->retryAfter)]),
            StaffSessionRefused::STEP_UP => ProblemDetails::response(401, $e->reason, $id),
            StaffSessionRefused::NOT_FOUND => ProblemDetails::response(404, $e->reason, $id),
            default => ProblemDetails::response(422, $e->reason, $id),
        };
    }
}
