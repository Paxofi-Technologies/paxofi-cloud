<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Application\Staff\StaffPrincipal;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\StaffSessionCookie;

/**
 * Enforces an admin route's access rule before its controller runs (§6.18,
 * T-31, T-32): staff session, CSRF token on writes, then the permission,
 * checked against the roles held right now.
 */
final readonly class AdminGate
{
    public function __construct(private StaffSessions $sessions, private StaffAuthorizer $authorizer, private StaffDirectory $directory)
    {
    }

    public function handle(AdminRoute $route, HttpRequest $request): HttpResponse
    {
        if ($route->access->public) {
            return ($route->handler)($request, new AdminContext());
        }

        $session = $this->sessions->resume(StaffSessionCookie::read($request));
        if ($session === null) {
            return ProblemDetails::response(401, 'not_signed_in', RequestId::of($request), headers: ['cache-control' => 'no-store']);
        }
        if ($route->method !== 'GET' && !$this->sessions->csrfMatches($session, $request->header('x-csrf-token'))) {
            return ProblemDetails::response(403, 'csrf_token_invalid', RequestId::of($request));
        }

        if ($route->access->permission === null) {
            $principal = new StaffPrincipal($session->staffId, $this->directory->activeRoles($session->staffId));
        } else {
            try {
                $principal = $this->authorizer->authorize($session->staffId, $route->access->permission, RequestClient::of($request));
            } catch (StaffPermissionDenied) {
                return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
            }
        }

        return ($route->handler)($request, new AdminContext($session, $principal));
    }
}
