<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Staff\Setup\InvalidStaffSetup;
use PaxofiCloud\Application\Staff\Setup\StaffSetupRefused;
use PaxofiCloud\Application\Staff\Setup\StaffSignInSetup;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * Setting up sign-in with an invitation token (D-11, §6.20): a password and
 * an authenticator app. A dead invitation is reported as such (only its
 * holder can ask); field errors name the field, never echo the value.
 */
final readonly class AdminEnrolmentController
{
    public function __construct(private StaffSignInSetup $setup)
    {
    }

    public function start(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $token = $data === null ? null : AdminJson::string($data, 'token', 64);
        if ($token === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->setup->startWithInvitation($token, RequestClient::of($request)));
        } catch (StaffSetupRefused $e) {
            return self::refused($e, $request);
        }
    }

    public function finish(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $token = $data === null ? null : AdminJson::string($data, 'token', 64);
        $password = $data === null ? null : AdminJson::string($data, 'password', 1024);
        $code = $data === null ? null : AdminJson::string($data, 'code', 32);
        if ($token === null || $password === null || $code === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok(['recovery_codes' => $this->setup->finishWithInvitation($token, $password, $code, RequestClient::of($request))]);
        } catch (StaffSetupRefused $e) {
            return self::refused($e, $request);
        } catch (InvalidStaffSetup $e) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request), errors: $e->errors);
        }
    }

    public static function refused(StaffSetupRefused $e, HttpRequest $request): HttpResponse
    {
        $id = RequestId::of($request);

        return match ($e->reason) {
            StaffSetupRefused::THROTTLED => ProblemDetails::response(429, 'too_many_attempts', $id, headers: ['retry-after' => (string) max(1, $e->retryAfter)]),
            StaffSetupRefused::INVITATION => ProblemDetails::response(400, 'invitation_invalid', $id),
            StaffSetupRefused::UNAVAILABLE => ProblemDetails::response(503, 'setup_unavailable', $id),
            default => ProblemDetails::response(409, 'setup_not_allowed', $id),
        };
    }
}
