<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Application\Staff\SignIn\StaffCodeSignIn;
use PaxofiCloud\Application\Staff\SignIn\StaffSignInRefused;
use PaxofiCloud\Application\Staff\SignIn\StaffSignInStep;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\StaffSessionCookie;
use PaxofiCloud\Http\StaffStepCookie;

/**
 * Staff sign-in with password, authenticator code and emailed code (§6.20).
 * The ticket between steps lives only in an HttpOnly cookie.
 */
final readonly class AdminCodeSignInController
{
    public function __construct(private StaffCodeSignIn $signIn, private StaffSessions $sessions)
    {
    }

    public function password(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $email = $data === null ? null : AdminJson::string($data, 'email', 254);
        $password = $data === null ? null : AdminJson::string($data, 'password', 1024);
        if ($email === null || $password === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return self::step($this->signIn->password($email, $password, RequestClient::of($request)));
        } catch (StaffSignInRefused $e) {
            return self::refused($e, $request);
        }
    }

    public function authenticatorCode(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $code = self::code($request);
        if ($code === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return self::step($this->signIn->authenticatorCode(StaffStepCookie::read($request), $code, RequestClient::of($request)));
        } catch (StaffSignInRefused $e) {
            return self::refused($e, $request);
        }
    }

    public function resendEmailCode(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return self::step($this->signIn->resendEmailCode(StaffStepCookie::read($request), RequestClient::of($request)));
        } catch (StaffSignInRefused $e) {
            return self::refused($e, $request);
        }
    }

    public function emailCode(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $code = self::code($request);
        if ($code === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $staffId = $this->signIn->emailCode(StaffStepCookie::read($request), $code, RequestClient::of($request));
        } catch (StaffSignInRefused $e) {
            return self::refused($e, $request);
        }
        // A fresh session ID at every sign-in (T-18); any older staff session in this browser ends.
        $this->sessions->end(StaffSessionCookie::read($request));
        $issued = $this->sessions->issue($staffId, RequestClient::of($request));

        return AdminJson::ok(['status' => 'signed_in', 'csrf_token' => $issued->csrfToken], headers: [
            'set-cookie' => [StaffSessionCookie::issue($issued->sessionId), StaffStepCookie::clear()],
        ]);
    }

    private static function code(HttpRequest $request): ?string
    {
        $data = JsonBody::object($request);

        return $data === null ? null : AdminJson::string($data, 'code', 32);
    }

    private static function step(StaffSignInStep $step): HttpResponse
    {
        return AdminJson::ok(['status' => 'code_required', 'next' => $step->next, 'expires_in' => $step->expiresIn], headers: [
            'set-cookie' => StaffStepCookie::issue($step->ticket, $step->expiresIn),
        ]);
    }

    private static function refused(StaffSignInRefused $e, HttpRequest $request): HttpResponse
    {
        $id = RequestId::of($request);
        // A refusal that keeps the sign-in going hands over the next ticket; any other ends it.
        $cookie = $e->ticket !== null ? StaffStepCookie::issue($e->ticket, 600) : StaffStepCookie::clear();

        return match ($e->reason) {
            StaffSignInRefused::THROTTLED => ProblemDetails::response(429, 'too_many_attempts', $id, headers: ['retry-after' => (string) max(1, $e->retryAfter), 'set-cookie' => $cookie]),
            StaffSignInRefused::CODE_INVALID => ProblemDetails::response(401, 'code_invalid', $id, headers: ['set-cookie' => $cookie]),
            StaffSignInRefused::EXPIRED => ProblemDetails::response(401, 'sign_in_expired', $id, headers: ['set-cookie' => $cookie]),
            StaffSignInRefused::UNAVAILABLE => ProblemDetails::response(503, 'sign_in_unavailable', $id, headers: ['set-cookie' => $cookie]),
            default => ProblemDetails::response(401, 'sign_in_failed', $id, headers: ['set-cookie' => $cookie]),
        };
    }
}
