<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\SessionCookie;

/**
 * POST /api/v1/auth/sign-out. Needs the session's CSRF token (T-21) so another
 * site cannot sign a customer out; the session is deleted on the server (IAM-009).
 */
final readonly class SignOutController implements Controller
{
    public function __construct(private Sessions $sessions, private AuditTrail $audit)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $sessionId = SessionCookie::read($request);
        $session = $this->sessions->resume($sessionId);
        if ($session === null) {
            // Nothing to end; still tell the browser to drop any stale cookie.
            return new Response(204, ['cache-control' => 'no-store', 'set-cookie' => SessionCookie::clear()], '');
        }
        if (!$this->sessions->csrfMatches($session, $request->header('x-csrf-token'))) {
            return ProblemDetails::response(403, 'csrf_token_invalid', RequestId::of($request));
        }

        $this->sessions->end($sessionId);
        $this->audit->record(AuditEventType::SIGNED_OUT, AuditEvent::SUCCESS, RequestClient::of($request), $session->identityId, $session->accountId);

        return new Response(204, ['cache-control' => 'no-store', 'set-cookie' => SessionCookie::clear()], '');
    }
}
