<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Identity\Session\Session;
use PaxofiCloud\Application\Identity\Session\Sessions;

/**
 * Resolves the customer behind a request (threat model D-13 step 1–2, T-21).
 * Every state-changing call also needs the session's own CSRF token.
 */
final class Authenticated
{
    /** @return Session|HttpResponse the session, or the refusal to return as-is */
    public static function session(HttpRequest $request, Sessions $sessions, bool $write): Session|HttpResponse
    {
        $session = $sessions->resume(SessionCookie::read($request));
        if ($session === null) {
            return ProblemDetails::response(401, 'not_signed_in', RequestId::of($request), headers: ['cache-control' => 'no-store']);
        }
        if ($write && !$sessions->csrfMatches($session, $request->header('x-csrf-token'))) {
            return ProblemDetails::response(403, 'csrf_token_invalid', RequestId::of($request));
        }

        return $session;
    }
}
