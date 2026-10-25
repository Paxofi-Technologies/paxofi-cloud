<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http\Admin;

use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Request;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Http\Admin\AdminGate;
use PaxofiCloud\Http\Admin\AdminRoute;
use PaxofiCloud\Http\Admin\AdminRoutes;
use PaxofiCloud\Http\Controllers\Admin\AdminAccountController;
use PaxofiCloud\Http\Controllers\Admin\AdminAuditLogController;
use PaxofiCloud\Http\Controllers\Admin\AdminAuthController;
use PaxofiCloud\Http\Controllers\Admin\AdminCodeSignInController;
use PaxofiCloud\Http\Controllers\Admin\AdminEnrolmentController;
use PaxofiCloud\Http\Controllers\Admin\AdminDomainCostsController;
use PaxofiCloud\Http\Controllers\Admin\AdminProviderConnectionsController;
use PaxofiCloud\Http\Controllers\Admin\AdminSessionsController;
use PaxofiCloud\Http\Controllers\Admin\AdminSpaceshipPriceCheckController;
use PaxofiCloud\Http\Controllers\Admin\AdminDomainExtensionsController;
use PaxofiCloud\Http\Controllers\Admin\AdminStaffController;
use PaxofiCloud\Http\StaffSessionCookie;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\InMemoryStaffSessionStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every admin route enforces its declared access rule before any controller
 * code runs (threat model T-31, T-32, §6.18). Controllers here throw if
 * reached, so a refusal proves the gate stopped the request.
 */
final class AdminRouteCoverageTest extends TestCase
{
    private const string NOBODY = 'b0000000000000000000000000000000';
    private const string MANAGER = 'a0000000000000000000000000000000';

    /** The only routes that may be called without a staff session: the sign-in steps and invitation setup (§6.20). */
    private const array PUBLIC = [
        'POST /admin-api/v1/auth/password',
        'POST /admin-api/v1/auth/authenticator-code',
        'POST /admin-api/v1/auth/email-code',
        'POST /admin-api/v1/auth/email-code/resend',
        'POST /admin-api/v1/auth/passkey/options',
        'POST /admin-api/v1/auth/passkey',
        'POST /admin-api/v1/enrolment/start',
        'POST /admin-api/v1/enrolment',
    ];

    /**
     * The exact rule of every non-public route: "signed-in" or the one permission it needs.
     * Changing a route's rule means changing this list too, in review (T-32).
     */
    private const array RULES = [
        'GET /admin-api/v1/auth/session' => 'signed-in',
        'POST /admin-api/v1/auth/sign-out' => 'signed-in',
        'POST /admin-api/v1/account/sign-in-setup/start' => 'signed-in',
        'POST /admin-api/v1/account/sign-in-setup' => 'signed-in',
        'POST /admin-api/v1/account/passkeys/options' => 'signed-in',
        'POST /admin-api/v1/account/passkeys' => 'signed-in',
        'GET /admin-api/v1/account/sessions' => 'signed-in',
        'POST /admin-api/v1/account/sessions/revoke' => 'signed-in',
        'POST /admin-api/v1/account/sessions/revoke-others' => 'signed-in',
        'GET /admin-api/v1/staff' => 'staff.manage',
        'POST /admin-api/v1/staff/invitations' => 'staff.manage',
        'POST /admin-api/v1/staff/roles/grant' => 'staff.manage',
        'POST /admin-api/v1/staff/roles/approve' => 'staff.manage',
        'POST /admin-api/v1/staff/roles/revoke' => 'staff.manage',
        'POST /admin-api/v1/staff/sessions/end' => 'staff.manage',
        'GET /admin-api/v1/provider-connections' => 'provider_credentials.manage',
        'POST /admin-api/v1/provider-connections' => 'provider_credentials.manage',
        'GET /admin-api/v1/domain-costs' => 'catalogue.view',
        'POST /admin-api/v1/domain-costs/sync' => 'catalogue.manage',
        'GET /admin-api/v1/spaceship-price-check' => 'catalogue.view',
        'POST /admin-api/v1/spaceship-price-check/run' => 'catalogue.manage',
        'GET /admin-api/v1/domain-extensions' => 'catalogue.view',
        'POST /admin-api/v1/domain-extensions/settings' => 'catalogue.manage',
        'POST /admin-api/v1/domain-pricing-settings' => 'catalogue.manage',
        'GET /admin-api/v1/audit-events' => 'audit.view',
    ];

    private InMemoryStaffDirectory $directory;
    private InMemoryAuditLog $audit;
    private StaffSessions $sessions;
    private AdminGate $gate;

    protected function setUp(): void
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->directory->add(self::NOBODY);
        $this->directory->add(self::MANAGER, 'active', StaffRole::SuperAdmin);
        $this->audit = new InMemoryAuditLog();
        $this->sessions = new StaffSessions(new InMemoryStaffSessionStore(), $this->directory, new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')));
        $this->gate = new AdminGate($this->sessions, new StaffAuthorizer($this->directory, AuditFixtures::trail($this->audit)), $this->directory);
    }

    /** @return iterable<string, array{AdminRoute}> */
    public static function routes(): iterable
    {
        foreach (self::table() as $route) {
            yield $route->method . ' ' . $route->path => [$route];
        }
    }

    public function testOnlyThePasskeyCeremoniesArePublicAndRoutesAreUnique(): void
    {
        $public = [];
        $seen = [];
        foreach (self::table() as $route) {
            $key = $route->method . ' ' . $route->path;
            self::assertArrayNotHasKey($key, $seen, 'duplicate admin route');
            $seen[$key] = true;
            if ($route->access->public) {
                $public[] = $key;
            }
        }
        self::assertSame(self::PUBLIC, $public);
    }

    public function testEveryRouteCarriesExactlyItsListedRule(): void
    {
        $actual = [];
        foreach (self::table() as $route) {
            if (!$route->access->public) {
                $actual[$route->method . ' ' . $route->path] = $route->access->permission === null ? 'signed-in' : $route->access->permission->value;
            }
        }
        self::assertSame(self::RULES, $actual);
    }

    #[DataProvider('routes')]
    public function testNonPublicRoutesRefuseRequestsWithoutAStaffSession(AdminRoute $route): void
    {
        if ($route->access->public) {
            $this->expectNotToPerformAssertions();

            return;
        }
        // No cookie, a malformed one, and a customer session cookie: all refused.
        foreach (['', '__Host-pc_staff=nope', '__Host-pc_sid=' . str_repeat('A', 43)] as $cookie) {
            self::assertSame(401, $this->call($route, $cookie, null)->status());
        }
    }

    #[DataProvider('routes')]
    public function testWritesNeedTheSessionsCsrfToken(AdminRoute $route): void
    {
        if ($route->access->public || $route->method === 'GET') {
            $this->expectNotToPerformAssertions();

            return;
        }
        $issued = $this->sessions->issue(self::MANAGER);
        $cookie = StaffSessionCookie::NAME . '=' . $issued->sessionId;

        self::assertSame(403, $this->call($route, $cookie, null)->status());
        self::assertSame(403, $this->call($route, $cookie, 'wrong-token')->status());
    }

    #[DataProvider('routes')]
    public function testPermissionRoutesRefuseStaffWithoutThatPermissionAndAuditIt(AdminRoute $route): void
    {
        $permission = $route->access->permission;
        if ($permission === null) {
            $this->expectNotToPerformAssertions();

            return;
        }
        $issued = $this->sessions->issue(self::NOBODY);
        $response = $this->call($route, StaffSessionCookie::NAME . '=' . $issued->sessionId, $issued->csrfToken);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('permission_denied', $response->body());
        self::assertSame([AuditEventType::STAFF_PERMISSION_DENIED], $this->audit->types());
        self::assertSame($permission->value, $this->audit->events[0]->details['permission'] ?? null);
    }

    /** With a session, the right CSRF token and the permission, the request does reach the controller. */
    #[DataProvider('routes')]
    public function testAuthorisedRequestsReachTheController(AdminRoute $route): void
    {
        $issued = $this->sessions->issue(self::MANAGER);
        $this->expectException(ReachedController::class);
        $this->call($route, StaffSessionCookie::NAME . '=' . $issued->sessionId, $issued->csrfToken);
    }

    public function testEveryPermissionUsedIsARealSingleCapability(): void
    {
        foreach (self::table() as $route) {
            if ($route->access->permission !== null) {
                self::assertContains($route->access->permission, Permission::cases());
            }
        }
    }

    private function call(AdminRoute $route, string $cookie, ?string $csrf): HttpResponse
    {
        $headers = ['host' => 'admin.example.test', 'origin' => 'https://admin.example.test', 'content-type' => 'application/json'];
        if ($cookie !== '') {
            $headers['cookie'] = $cookie;
        }
        if ($csrf !== null) {
            $headers['x-csrf-token'] = $csrf;
        }

        return $this->gate->handle($route, new Request($route->method, $route->path, $headers, '{}'));
    }

    /** @return list<AdminRoute> */
    private static function table(): array
    {
        return AdminRoutes::all(
            static fn (): AdminAuthController => throw new ReachedController(),
            static fn (): AdminEnrolmentController => throw new ReachedController(),
            static fn (): AdminStaffController => throw new ReachedController(),
            static fn (): AdminCodeSignInController => throw new ReachedController(),
            static fn (): AdminAccountController => throw new ReachedController(),
            static fn (): AdminProviderConnectionsController => throw new ReachedController(),
            static fn (): AdminDomainCostsController => throw new ReachedController(),
            static fn (): AdminAuditLogController => throw new ReachedController(),
            static fn (): AdminSessionsController => throw new ReachedController(),
            static fn (): AdminSpaceshipPriceCheckController => throw new ReachedController(),
            static fn (): AdminDomainExtensionsController => throw new ReachedController(),
        );
    }
}

final class ReachedController extends \RuntimeException
{
}
