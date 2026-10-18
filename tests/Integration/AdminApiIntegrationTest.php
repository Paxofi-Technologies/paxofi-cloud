<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Http\StaffSessionCookie;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PDO;

/**
 * The admin API through the real HTTP stack, MySQL 8.4 and Redis 7 (SRS
 * IAM-008, ACC-005; threat model D-6, T-31, T-32, §6.17, §6.18).
 */
final class AdminApiIntegrationTest extends IntegrationTestCase
{
    private const string ADMIN = 'https://admin.example.test';
    private const string ADMIN_HOST = 'admin.example.test';

    private OutboxSealer $opener;
    private PDO $pdo;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $pair = OutboxSealer::generateKeyPair();
        putenv('EMAIL_OUTBOX_PUBLIC_KEY=' . $pair['public']);
        putenv('ADMIN_URL=' . self::ADMIN);
        $this->opener = OutboxSealer::forOpening($pair['public'], $pair['secret']);
        $app = AppFactory::create(self::basePath());
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator(Resolve::get($app->container(), Connection::class), $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->pdo = self::pdo();
        $this->ip = '198.18.' . random_int(0, 255) . '.' . random_int(1, 254);

        Resolve::get($app->container(), StaffInvitations::class)
            ->bootstrap('ada@example.test', 'Ada Lovelace', ClientContext::from('127.0.0.1', 'cli', null, null, null, 'cli'));
    }

    protected function tearDown(): void
    {
        putenv('ADMIN_URL');
        parent::tearDown();
    }

    public function testEnrolSignInManageAndSignOutOverHttp(): void
    {
        $token = $this->latestToken('staff-invitation');
        $key1 = new SoftwareAuthenticator(self::ADMIN);
        $key2 = new SoftwareAuthenticator(self::ADMIN, -8);
        self::assertSame(['passkeys' => 1, 'active' => false], self::json($this->enrol($token, $key1, 'Laptop')));
        self::assertSame(['passkeys' => 2, 'active' => true], self::json($this->enrol($token, $key2, 'YubiKey')));

        $signedIn = $this->signIn($key1);
        self::assertSame(200, $signedIn->status());
        $cookie = $this->staffCookie($signedIn);
        $csrf = self::json($signedIn)['csrf_token'] ?? null;
        self::assertIsString($csrf);

        $session = $this->admin('GET', '/admin-api/v1/auth/session', null, $cookie);
        self::assertSame(200, $session->status());
        self::assertSame('no-store', $session->header('cache-control'));
        $staff = self::json($session)['staff'] ?? null;
        self::assertIsArray($staff);
        self::assertSame('Ada Lovelace', $staff['full_name']);
        self::assertSame(['security_admin'], $staff['roles']);
        self::assertIsArray($staff['permissions']);
        self::assertContains('staff.manage', $staff['permissions']);
        self::assertNotContains('settings.manage', $staff['permissions']);

        // Writes need the CSRF token.
        self::assertSame(403, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie)->status());
        $invited = $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie, $csrf);
        self::assertSame(201, $invited->status());
        self::assertSame(409, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie, $csrf)->status());
        self::assertSame(422, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'eve@example.test', 'full_name' => 'Eve', 'role' => 'super_admin'], $cookie, $csrf)->status());

        $list = self::json($this->admin('GET', '/admin-api/v1/staff', null, $cookie));
        self::assertIsArray($list['staff'] ?? null);
        self::assertCount(2, $list['staff']);

        // Bob enrols with his own invitation; Ada is alerted.
        $bobToken = $this->latestToken('staff-invitation');
        $this->enrol($bobToken, new SoftwareAuthenticator(self::ADMIN));
        $this->enrol($bobToken, new SoftwareAuthenticator(self::ADMIN));
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'staff-activated'"));

        self::assertSame(204, $this->admin('POST', '/admin-api/v1/auth/sign-out', [], $cookie, $csrf)->status());
        self::assertSame(401, $this->admin('GET', '/admin-api/v1/auth/session', null, $cookie)->status());
    }

    public function testSupportStaffCannotManageStaff(): void
    {
        $this->activateAda();
        $adaCookie = $this->staffCookie($this->signIn($this->adaKey));
        $csrf = self::json($this->admin('GET', '/admin-api/v1/auth/session', null, $adaCookie))['csrf_token'] ?? '';
        self::assertIsString($csrf);
        $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'sam@example.test', 'full_name' => 'Sam', 'role' => 'support'], $adaCookie, $csrf);
        $sam = new SoftwareAuthenticator(self::ADMIN);
        $samToken = $this->latestToken('staff-invitation');
        $this->enrol($samToken, $sam);
        $this->enrol($samToken, new SoftwareAuthenticator(self::ADMIN));

        $samCookie = $this->staffCookie($this->signIn($sam));
        $response = $this->admin('GET', '/admin-api/v1/staff', null, $samCookie);
        self::assertSame(403, $response->status());
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM audit_events WHERE event_type = 'staff.permission_denied'"));
    }

    public function testFailedSignInIsGeneric(): void
    {
        $this->activateAda();
        $response = $this->signIn(new SoftwareAuthenticator(self::ADMIN));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('sign_in_failed', $response->body());
        self::assertNull($response->header('set-cookie'));
    }

    /** D-6, T-31: the admin API is invisible on the customer host, and the two cookies never cross. */
    public function testAdminAndCustomerSurfacesStaySeparate(): void
    {
        $this->activateAda();
        $staffCookie = $this->staffCookie($this->signIn($this->adaKey));

        self::assertSame(404, $this->send('GET', '/admin-api/v1/auth/session', '', ['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_COOKIE' => $staffCookie])->status());
        self::assertSame(401, $this->send('GET', '/api/v1/auth/session', '', ['HTTP_HOST' => self::ADMIN_HOST, 'HTTP_COOKIE' => $staffCookie])->status());
        self::assertSame(401, $this->admin('GET', '/admin-api/v1/auth/session', null, SessionCookie::NAME . '=' . explode('=', $staffCookie, 2)[1])->status());
        self::assertSame(403, $this->send('POST', '/admin-api/v1/auth/passkey/options', '{}', ['HTTP_HOST' => self::ADMIN_HOST, 'HTTP_ORIGIN' => 'http://127.0.0.1:8080'])->status());
    }

    private SoftwareAuthenticator $adaKey;

    private function activateAda(): void
    {
        $token = $this->latestToken('staff-invitation');
        $this->adaKey = new SoftwareAuthenticator(self::ADMIN);
        $this->enrol($token, $this->adaKey);
        $this->enrol($token, new SoftwareAuthenticator(self::ADMIN));
    }

    private function enrol(string $token, SoftwareAuthenticator $authenticator, string $label = ''): HttpResponse
    {
        $options = self::json($this->admin('POST', '/admin-api/v1/enrolment/options', ['token' => $token]));
        self::assertIsString($options['ceremony_id'] ?? null);
        self::assertIsArray($options['options'] ?? null);
        /** @var array<string, mixed> $creation */
        $creation = $options['options'];
        $credential = json_decode($authenticator->register($creation), true, 16, JSON_THROW_ON_ERROR);

        return $this->admin('POST', '/admin-api/v1/enrolment', ['token' => $token, 'ceremony_id' => $options['ceremony_id'], 'credential' => $credential, 'label' => $label]);
    }

    private function signIn(SoftwareAuthenticator $authenticator): HttpResponse
    {
        $options = self::json($this->admin('POST', '/admin-api/v1/auth/passkey/options', []));
        self::assertIsString($options['ceremony_id'] ?? null);
        self::assertIsArray($options['options'] ?? null);
        /** @var array<string, mixed> $request */
        $request = $options['options'];
        $handle = $this->pdo->prepare('SELECT m.webauthn_user_handle FROM staff_passkeys p JOIN staff_members m ON m.id = p.staff_id WHERE p.credential_lookup = ?');
        $handle->execute([hash('sha256', $authenticator->credentialId)]);
        $userHandle = $handle->fetchColumn();
        $credential = json_decode($authenticator->assert($request, is_string($userHandle) ? $userHandle : random_bytes(32)), true, 16, JSON_THROW_ON_ERROR);

        return $this->admin('POST', '/admin-api/v1/auth/passkey', ['ceremony_id' => $options['ceremony_id'], 'credential' => $credential]);
    }

    private function staffCookie(HttpResponse $response): string
    {
        $header = $response->header('set-cookie');
        self::assertIsString($header);
        self::assertStringStartsWith(StaffSessionCookie::NAME . '=', $header);
        self::assertStringContainsString('SameSite=Strict', $header);

        return explode(';', $header)[0];
    }

    /** @param array<string, mixed>|null $data */
    private function admin(string $method, string $path, ?array $data, string $cookie = '', ?string $csrf = null): HttpResponse
    {
        $server = ['HTTP_HOST' => self::ADMIN_HOST, 'HTTP_ORIGIN' => self::ADMIN];
        if ($cookie !== '') {
            $server['HTTP_COOKIE'] = $cookie;
        }
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        return $this->send($method, $path, $data === null ? '' : json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $server);
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $path, string $body, array $server): HttpResponse
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $body);
        rewind($stream);
        $request = (new RequestFactory())->fromGlobals($server + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ], [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }

    private function latestToken(string $template): string
    {
        $sealed = $this->scalar('SELECT payload FROM email_outbox WHERE template = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$template]);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $parameters = $payload['parameters'] ?? null;
        self::assertIsArray($parameters);
        self::assertIsString($parameters['token'] ?? null);

        return $parameters['token'];
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters = []): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();
        self::assertTrue(is_string($value) || is_int($value), 'no scalar for: ' . $sql);

        return (string) $value;
    }

    /** @return array<array-key, mixed> */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
