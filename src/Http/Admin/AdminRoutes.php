<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Http\Controllers\Admin\AdminAccountController;
use PaxofiCloud\Http\Controllers\Admin\AdminAuditLogController;
use PaxofiCloud\Http\Controllers\Admin\AdminAuthController;
use PaxofiCloud\Http\Controllers\Admin\AdminCodeSignInController;
use PaxofiCloud\Http\Controllers\Admin\AdminDomainCostsController;
use PaxofiCloud\Http\Controllers\Admin\AdminEnrolmentController;
use PaxofiCloud\Http\Controllers\Admin\AdminProviderConnectionsController;
use PaxofiCloud\Http\Controllers\Admin\AdminSessionsController;
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
     * @param \Closure(): AdminCodeSignInController $codes
     * @param \Closure(): AdminAccountController $account
     * @param \Closure(): AdminProviderConnectionsController $providers
     * @param \Closure(): AdminDomainCostsController $domainCosts
     * @param \Closure(): AdminAuditLogController $auditLog
     * @param \Closure(): AdminSessionsController $sessions
     * @return list<AdminRoute>
     */
    public static function all(\Closure $auth, \Closure $enrolment, \Closure $staff, \Closure $codes, \Closure $account, \Closure $providers, \Closure $domainCosts, \Closure $auditLog, \Closure $sessions): array
    {
        $credentials = AdminAccess::permission(Permission::ProviderCredentialsManage);
        $public = AdminAccess::public();
        $signedIn = AdminAccess::signedIn();
        $manage = AdminAccess::permission(Permission::StaffManage);
        $catalogueView = AdminAccess::permission(Permission::CatalogueView);
        $catalogueManage = AdminAccess::permission(Permission::CatalogueManage);
        $auditView = AdminAccess::permission(Permission::AuditView);

        return [
            // Each step proves itself: a password, the sign-in ticket cookie, a passkey assertion, or an invitation token (§6.20).
            new AdminRoute('POST', '/admin-api/v1/auth/password', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $codes()->password($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/authenticator-code', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $codes()->authenticatorCode($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/email-code', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $codes()->emailCode($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/email-code/resend', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $codes()->resendEmailCode($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/passkey/options', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->options($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/passkey', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->signIn($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/enrolment/start', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $enrolment()->start($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/enrolment', $public, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $enrolment()->finish($r, $c)),

            new AdminRoute('GET', '/admin-api/v1/auth/session', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->session($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/auth/sign-out', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auth()->signOut($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/sign-in-setup/start', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $account()->startSetup($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/sign-in-setup', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $account()->finishSetup($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/passkeys/options', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $account()->passkeyOptions($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/passkeys', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $account()->addPasskey($r, $c)),
            // Own sessions: list, and sign-out with a fresh authenticator code (§6.25).
            new AdminRoute('GET', '/admin-api/v1/account/sessions', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $sessions()->list($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/sessions/revoke', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $sessions()->revoke($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/account/sessions/revoke-others', $signedIn, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $sessions()->revokeOthers($r, $c)),

            new AdminRoute('GET', '/admin-api/v1/staff', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->list($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/invitations', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->invite($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/grant', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->grant($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/approve', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->approve($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/roles/revoke', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $staff()->revoke($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/staff/sessions/end', $manage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $sessions()->endForMember($r, $c)),

            // Provider connections: status out, secrets in, never back out (PC-2, PC-3).
            new AdminRoute('GET', '/admin-api/v1/provider-connections', $credentials, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $providers()->list($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/provider-connections', $credentials, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $providers()->submit($r, $c)),

            // Domain costs: internal prices for Catalogue and Finance; "Sync now" queues work for the provider worker (DR-12, DR-13).
            new AdminRoute('GET', '/admin-api/v1/domain-costs', $catalogueView, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $domainCosts()->overview($r, $c)),
            new AdminRoute('POST', '/admin-api/v1/domain-costs/sync', $catalogueManage, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $domainCosts()->sync($r, $c)),

            // Audit log: read-only; full IPs only for staff managers; every look is itself audited (§6.24).
            new AdminRoute('GET', '/admin-api/v1/audit-events', $auditView, static fn (HttpRequest $r, AdminContext $c): HttpResponse => $auditLog()->list($r, $c)),
        ];
    }

    private function __construct()
    {
    }
}
