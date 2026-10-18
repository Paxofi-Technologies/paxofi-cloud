<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\InvalidMfaInput;
use PaxofiCloud\Application\Identity\Mfa\MfaThrottled;
use PaxofiCloud\Application\Identity\Mfa\MfaTicketInvalid;
use PaxofiCloud\Application\Identity\Mfa\MfaUnavailable;
use PaxofiCloud\Application\Identity\SignIn\AccountUnavailable;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\MfaCookie;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\SessionCookie;

/**
 * POST /api/v1/auth/sign-in/mfa `{code}` or `{recovery_code}` with the
 * `__Host-pc_mfa` ticket cookie (SRS IAM-007; threat model §6.13, T-14).
 */
final readonly class SignInMfaController implements Controller
{
    public function __construct(private CompleteMfaSignIn $complete)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_json', RequestId::of($request));
        }

        $id = RequestId::of($request);
        $expired = ['set-cookie' => MfaCookie::clear()];
        try {
            $issued = $this->complete->complete(MfaCookie::read($request), $data, JsonBody::clientIp($request), (string) $request->header('user-agent'));
        } catch (InvalidMfaInput $e) {
            return ProblemDetails::response(422, 'validation_failed', $id, errors: $e->errors);
        } catch (MfaTicketInvalid) {
            return ProblemDetails::response(401, 'mfa_ticket_invalid', $id, headers: $expired);
        } catch (MfaThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', $id, headers: $expired + ['retry-after' => (string) $e->retryAfterSeconds]);
        } catch (MfaUnavailable) {
            return ProblemDetails::response(503, 'mfa_unavailable', $id);
        } catch (AccountUnavailable) {
            return ProblemDetails::response(403, 'account_unavailable', $id, headers: $expired);
        }

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store', 'set-cookie' => [MfaCookie::clear(), SessionCookie::issue($issued->sessionId)]],
            json_encode(['status' => 'signed_in', 'csrf_token' => $issued->csrfToken], JSON_THROW_ON_ERROR),
        );
    }
}
