<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitationRefused;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Application\Staff\StaffRoleChangeRefused;
use PaxofiCloud\Application\Staff\StaffRoles;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** Staff management for staff managers (ACC-005, SEC-017, D-11): list, invite, grant, approve, revoke. */
final readonly class AdminStaffController
{
    public function __construct(private StaffDirectory $directory, private StaffInvitations $invitations, private StaffRoles $roles)
    {
    }

    public function list(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return AdminJson::ok([
            'staff' => array_map(static fn (array $m): array => [
                'id' => $m['id'],
                'full_name' => $m['full_name'],
                'email' => $m['email'],
                'status' => $m['status'],
                'roles' => array_map(static fn (StaffRole $r): string => $r->value, $m['roles']),
            ], $this->directory->members()),
            'pending_grants' => array_map(static fn (array $g): array => [
                'grant_id' => $g['grant_id'],
                'staff_id' => $g['staff_id'],
                'role' => $g['role']->value,
                'granted_by' => $g['granted_by'],
            ], $this->directory->pendingGrants()),
        ]);
    }

    public function invite(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $email = $data === null ? null : AdminJson::string($data, 'email', 254);
        $name = $data === null ? null : AdminJson::string($data, 'full_name', 400);
        $role = $data === null ? null : self::role($data);
        if ($email === null || $name === null || $role === null) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request));
        }
        try {
            $staffId = $this->invitations->invite($context->staffId(), $email, $name, $role, RequestClient::of($request));
        } catch (StaffInvitationRefused $e) {
            return ProblemDetails::response($e->reason === StaffInvitationRefused::ALREADY_STAFF ? 409 : 422, $e->reason, RequestId::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }

        return AdminJson::ok(['staff_id' => $staffId, 'status' => 'invited'], 201);
    }

    public function grant(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $staffId = $data === null ? null : AdminJson::string($data, 'staff_id', 64);
        $role = $data === null ? null : self::role($data);
        if ($staffId === null || $role === null) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request));
        }

        return $this->change($request, fn (): array => ['status' => $this->roles->grant($context->staffId(), $staffId, $role, RequestClient::of($request))]);
    }

    public function approve(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $grantId = $data === null ? null : AdminJson::string($data, 'grant_id', 64);
        if ($grantId === null) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request));
        }

        return $this->change($request, function () use ($context, $grantId, $request): array {
            $this->roles->approve($context->staffId(), $grantId, RequestClient::of($request));

            return ['status' => 'active'];
        });
    }

    public function revoke(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $data = JsonBody::object($request);
        $staffId = $data === null ? null : AdminJson::string($data, 'staff_id', 64);
        $role = $data === null ? null : self::role($data);
        if ($staffId === null || $role === null) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request));
        }

        return $this->change($request, function () use ($context, $staffId, $role, $request): array {
            $this->roles->revoke($context->staffId(), $staffId, $role, RequestClient::of($request));

            return ['status' => 'revoked'];
        });
    }

    /** @param \Closure(): array<string, string> $change */
    private function change(HttpRequest $request, \Closure $change): HttpResponse
    {
        try {
            return AdminJson::ok($change());
        } catch (StaffRoleChangeRefused $e) {
            return ProblemDetails::response(409, $e->reason, RequestId::of($request));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    /** @param array<array-key, mixed> $data */
    private static function role(array $data): ?StaffRole
    {
        $value = $data['role'] ?? null;

        return is_string($value) ? StaffRole::tryFrom($value) : null;
    }
}
