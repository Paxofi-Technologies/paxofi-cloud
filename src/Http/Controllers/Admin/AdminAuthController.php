<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Contracts\StaffCredentialStore;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Contracts\StaffPasskeyStore;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;
use PaxofiCloud\Http\StaffSessionCookie;

/**
 * Staff passkey sign-in (optional since §6.20), session and sign-out (IAM-008; §6.17, §6.18). Every
 * sign-in failure is the same 401; the reason is only in the audit log.
 */
final readonly class AdminAuthController
{
    public function __construct(
        private StaffPasskeySignIn $signIn,
        private StaffSessions $sessions,
        private StaffDirectory $directory,
        private AuditTrail $audit,
        private StaffCredentialStore $credentials,
        private StaffPasskeyStore $passkeys,
    ) {
    }

    public function options(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->signIn->begin(RequestClient::of($request)));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
    }

    public function signIn(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $ceremony = $data === null ? null : AdminJson::string($data, 'ceremony_id', 64);
        $credential = $data === null ? null : AdminJson::credential($data);
        if ($ceremony === null || $credential === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $staffId = $this->signIn->finish($ceremony, $credential, RequestClient::of($request));
        } catch (PasskeyRejected $e) {
            return self::rejected($e, $request);
        }
        // A fresh session ID at every sign-in (T-18); any older staff session in this browser ends.
        $this->sessions->end(StaffSessionCookie::read($request));
        $issued = $this->sessions->issue($staffId);

        return AdminJson::ok(['status' => 'signed_in', 'csrf_token' => $issued->csrfToken], headers: ['set-cookie' => StaffSessionCookie::issue($issued->sessionId)]);
    }

    public function session(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $staffId = $context->staffId();
        $member = null;
        foreach ($this->directory->members() as $candidate) {
            if ($candidate['id'] === $staffId) {
                $member = $candidate;
            }
        }
        $principal = $context->principal;
        $permissions = [];
        foreach (Permission::cases() as $permission) {
            if ($principal !== null && $principal->can($permission)) {
                $permissions[] = $permission->value;
            }
        }

        return AdminJson::ok([
            'staff' => [
                'id' => $staffId,
                'full_name' => $member['full_name'] ?? '',
                'email' => $member['email'] ?? '',
                'roles' => array_map(static fn ($r): string => $r->value, $principal->roles ?? []),
                'permissions' => $permissions,
                // §6.20: which sign-in methods the member has, so the console can offer the missing ones.
                'sign_in' => [
                    'password' => ($this->credentials->member($staffId)['has_password'] ?? false) === true,
                    'passkeys' => count($this->passkeys->credentialIdsOf($staffId)),
                ],
            ],
            'csrf_token' => $context->session?->csrfToken,
        ]);
    }

    public function signOut(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $this->sessions->end(StaffSessionCookie::read($request));
        $this->audit->record(AuditEventType::STAFF_SIGNED_OUT, AuditEvent::SUCCESS, RequestClient::of($request), details: ['subject' => $context->staffId()]);

        return new Response(204, ['cache-control' => 'no-store', 'set-cookie' => StaffSessionCookie::clear()], '');
    }

    private static function rejected(PasskeyRejected $e, HttpRequest $request): HttpResponse
    {
        if ($e->reason === PasskeyRejected::THROTTLED) {
            return ProblemDetails::response(429, 'too_many_attempts', RequestId::of($request), headers: ['retry-after' => (string) max(1, $e->retryAfter)]);
        }

        return ProblemDetails::response(401, 'sign_in_failed', RequestId::of($request));
    }
}
