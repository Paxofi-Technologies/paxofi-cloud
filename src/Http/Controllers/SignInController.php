<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Challenge\ChallengeUnavailable;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\MfaPending;
use PaxofiCloud\Application\Identity\SignIn\AccountUnavailable;
use PaxofiCloud\Application\Identity\SignIn\EmailNotVerified;
use PaxofiCloud\Application\Identity\SignIn\InvalidCredentials;
use PaxofiCloud\Application\Identity\SignIn\InvalidSignIn;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Identity\SignIn\SignInThrottled;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\MfaCookie;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\SessionCookie;
use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;

/**
 * POST /api/v1/auth/sign-in (SRS IAM-005, IAM-006). Unknown email and wrong
 * password share one 401 (T-09). Success sets the session cookie and returns
 * the CSRF token for later writes (T-21).
 */
final readonly class SignInController implements Controller
{
    public function __construct(private SignIn $signIn)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_json', RequestId::of($request));
        }

        $id = RequestId::of($request);
        try {
            $issued = $this->signIn->signIn($data, JsonBody::clientIp($request), (string) $request->header('user-agent'), SessionCookie::read($request));
        } catch (InvalidSignIn $e) {
            return ProblemDetails::response(422, 'validation_failed', $id, errors: $e->errors);
        } catch (ChallengeRequired) {
            return ProblemDetails::response(403, 'challenge_required', RequestId::of($request));
        } catch (ChallengeUnavailable) {
            return ProblemDetails::response(503, 'challenge_unavailable', RequestId::of($request), headers: ['retry-after' => '30']);
        } catch (SignInThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', $id, headers: ['retry-after' => (string) $e->retryAfterSeconds]);
        } catch (InvalidCredentials) {
            return ProblemDetails::response(401, 'invalid_credentials', $id);
        } catch (EmailNotVerified) {
            return ProblemDetails::response(403, 'email_not_verified', $id);
        } catch (AccountUnavailable) {
            return ProblemDetails::response(403, 'account_unavailable', $id);
        }

        if ($issued instanceof MfaPending) {
            // §6.13: no session yet, only the short-lived second-step ticket.
            return new Response(
                200,
                ['content-type' => 'application/json', 'cache-control' => 'no-store', 'set-cookie' => [SessionCookie::clear(), MfaCookie::issue($issued->ticket, CompleteMfaSignIn::TICKET_TTL_SECONDS)]],
                '{"status":"mfa_required"}',
            );
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store', 'set-cookie' => SessionCookie::issue($issued->sessionId)],
            json_encode(['status' => 'signed_in', 'csrf_token' => $issued->csrfToken], JSON_THROW_ON_ERROR),
        );
    }
}
