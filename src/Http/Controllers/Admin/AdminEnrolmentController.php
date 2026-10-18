<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffEnrolment;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * Passkey enrolment with an invitation token (D-11, §6.17). A dead invitation
 * is reported as such (only its holder can ask); every other failure is one
 * generic code.
 */
final readonly class AdminEnrolmentController
{
    public function __construct(private StaffEnrolment $enrolment)
    {
    }

    public function options(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $token = $data === null ? null : AdminJson::string($data, 'token', 64);
        if ($token === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->enrolment->begin($token, RequestClient::of($request)));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
    }

    public function finish(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $token = $data === null ? null : AdminJson::string($data, 'token', 64);
        $ceremony = $data === null ? null : AdminJson::string($data, 'ceremony_id', 64);
        $credential = $data === null ? null : AdminJson::credential($data);
        $label = $data === null ? '' : (AdminJson::string($data, 'label', 256) ?? '');
        if ($token === null || $ceremony === null || $credential === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return AdminJson::ok($this->enrolment->finish($token, $ceremony, $credential, $label, RequestClient::of($request)));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
    }

    private static function rejected(PasskeyRejected $e, HttpRequest $request): HttpResponse
    {
        return match ($e->reason) {
            PasskeyRejected::THROTTLED => ProblemDetails::response(429, 'too_many_attempts', RequestId::of($request), headers: ['retry-after' => (string) max(1, $e->retryAfter)]),
            PasskeyRejected::INVITATION => ProblemDetails::response(400, 'invitation_invalid', RequestId::of($request)),
            PasskeyRejected::LIMIT => ProblemDetails::response(409, 'passkey_limit_reached', RequestId::of($request)),
            default => ProblemDetails::response(400, 'passkey_rejected', RequestId::of($request)),
        };
    }
}
