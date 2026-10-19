<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeyRegistration;
use PaxofiCloud\Application\Staff\Setup\InvalidStaffSetup;
use PaxofiCloud\Application\Staff\Setup\StaffSetupRefused;
use PaxofiCloud\Application\Staff\Setup\StaffSignInSetup;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * The signed-in member's own sign-in methods (§6.20): set up password and
 * authenticator once (members who enrolled with passkeys only), and add an
 * optional passkey with a current authenticator code.
 */
final readonly class AdminAccountController
{
    public function __construct(private StaffSignInSetup $setup, private StaffPasskeyRegistration $passkeys)
    {
    }

    public function startSetup(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->setup->startForMember($context->staffId(), RequestClient::of($request)));
        } catch (StaffSetupRefused $e) {
            return AdminEnrolmentController::refused($e, $request);
        }
    }

    public function finishSetup(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $password = $data === null ? null : AdminJson::string($data, 'password', 1024);
        $code = $data === null ? null : AdminJson::string($data, 'code', 32);
        if ($password === null || $code === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok(['recovery_codes' => $this->setup->finishForMember($context->staffId(), $password, $code, RequestClient::of($request))]);
        } catch (StaffSetupRefused $e) {
            return AdminEnrolmentController::refused($e, $request);
        } catch (InvalidStaffSetup $e) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request), errors: $e->errors);
        }
    }

    public function passkeyOptions(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $code = $data === null ? null : AdminJson::string($data, 'code', 32);
        if ($code === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->passkeys->begin($context->staffId(), $code, RequestClient::of($request)));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
    }

    public function addPasskey(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $ceremony = $data === null ? null : AdminJson::string($data, 'ceremony_id', 64);
        $credential = $data === null ? null : AdminJson::credential($data);
        $label = $data === null ? '' : (AdminJson::string($data, 'label', 256) ?? '');
        if ($ceremony === null || $credential === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->passkeys->finish($context->staffId(), $ceremony, $credential, $label, RequestClient::of($request)));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
    }

    private static function rejected(PasskeyRejected $e, HttpRequest $request): HttpResponse
    {
        $id = RequestId::of($request);

        return match ($e->reason) {
            PasskeyRejected::THROTTLED => ProblemDetails::response(429, 'too_many_attempts', $id, headers: ['retry-after' => (string) max(1, $e->retryAfter)]),
            PasskeyRejected::STEP_UP => ProblemDetails::response(401, 'code_invalid', $id),
            PasskeyRejected::LIMIT => ProblemDetails::response(409, 'passkey_limit_reached', $id),
            default => ProblemDetails::response(400, 'passkey_rejected', $id),
        };
    }
}
