<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\SessionCookie;

/**
 * GET /api/v1/auth/session: who is signed in, plus the session's CSRF token.
 * A session whose identity is no longer active (suspended) is ended here.
 */
final readonly class SessionController implements Controller
{
    public function __construct(private Sessions $sessions, private SignInStore $identities)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $sessionId = SessionCookie::read($request);
        $session = $this->sessions->resume($sessionId);
        $profile = $session === null ? null : $this->identities->findProfile($session->identityId);
        if ($session === null || $profile === null || $profile['status'] !== 'active') {
            $this->sessions->end($sessionId);
            $headers = $request->header('cookie') !== null ? ['set-cookie' => SessionCookie::clear()] : [];

            return ProblemDetails::response(401, 'not_signed_in', RequestId::of($request), headers: ['cache-control' => 'no-store'] + $headers);
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode([
                'identity' => ['full_name' => $profile['full_name'], 'email' => $profile['email']],
                'csrf_token' => $session->csrfToken,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
