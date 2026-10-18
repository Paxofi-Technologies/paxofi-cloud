<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Http\Controllers\Admin\AdminAuthController;
use PaxofiCloud\Http\Controllers\Admin\AdminEnrolmentController;
use PaxofiCloud\Http\Controllers\Admin\AdminStaffController;

/**
 * Every admin API route and its one access rule (§6.18, T-32). The router is
 * built from this list only, so a route cannot exist without a rule;
 * AdminRouteCoverageTest checks the rules are enforced.
 */
final class AdminRoutes
{
    /**
     * @param \Closure(): AdminAuthController $auth
     * @param \Closure(): AdminEnrolmentController $enrolment
     * @param \Closure(): AdminStaffController $staff
     * @return list<AdminRoute>
     */
    public static function all(\Closure $auth, \Closure $enrolment, \Closure $staff): array
    {
        $public = AdminAccess::public();
        $signedIn = AdminAccess::signedIn();
        $manage = AdminAccess::permission(Permission::StaffManage);

        return [
            // Ceremonies prove themselves: a passkey assertion, or an invitation token.
            new AdminRoute('POST', '/admin-api/v1/auth/passkey/options', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->options($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/passkey', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->signIn($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/enrolment/options', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $enrolment()->options($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/enrolment', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $enrolment()->finish($r, $c)),

            new AdminRoute('GET', '/admin-api/v1/auth/session', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->session($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/sign-out', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->signOut($r, $c)),

            new AdminRoute('GET', '/admin-api/v1/staff', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->list($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/invitations', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->invite($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/grant', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->grant($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/approve', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->approve($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/revoke', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->revoke($r, $c)),
        ];
    }

    private function __construct()
    {
    }
}
