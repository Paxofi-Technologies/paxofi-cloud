<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Http\StaffSessionCookie;
use PaxofiCloud\Http\StaffStepCookie;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PDO;

/**
 * The admin API through the real HTTP stack, MySQL 8.4 and Redis 7 (SRS
 * IAM-008, ACC-005; threat model D-6, T-31, T-32, §6.17, §6.18, §6.20).
 */
final class AdminApiIntegrationTest extends IntegrationTestCase
{
    private const string ADMIN = 'https://admin.example.test';
    private const string ADMIN_HOST = 'admin.example.test';

    private const string PASSWORD = 'correct horse battery staple';

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
        // The per-address password limit is not per IP, so its Redis window would outlive one test.
        foreach (['ada@example.test', 'sam@example.test', 'nobody@example.test'] as $address) {
            Resolve::get($app->container(), RedisClient::class)->delete('pc:ratelimit:staff:password:address:' . hash('sha256', $address));
        }
        // TOTP: do not start in the last seconds of a 30-second step (see totp()).
        $left = Totp::PERIOD - time() % Totp::PERIOD;
        if ($left <= 10) {
            sleep($left);
        }

        Resolve::get($app->container(), StaffInvitations::class)
            ->bootstrap('ada@example.test', 'Ada Lovelace', ClientContext::from('127.0.0.1', 'cli', null, null, null, 'cli'));
    }

    protected function tearDown(): void
    {
        putenv('ADMIN_URL');
        parent::tearDown();
    }

    public function testSetUpSignInWithCodesManageAndSignOutOverHttp(): void
    {
        $token = $this->latestParameter('staff-invitation', 'token');
        $started = $this->admin('POST', '/admin-api/v1/enrolment/start', ['token' => $token]);
        self::assertSame(200, $started->status());
        self::assertSame('no-store', $started->header('cache-control'));
        $setup = self::json($started);
        self::assertSame('Ada Lovelace', $setup['full_name']);
        self::assertIsString($setup['secret']);
        self::assertIsString($setup['uri']);
        $ada = $this->scalar('SELECT id FROM staff_members');

        $weak = $this->admin('POST', '/admin-api/v1/enrolment', ['token' => $token, 'password' => 'short', 'code' => $this->totp($ada, -1)]);
        self::assertSame(422, $weak->status());
        self::assertSame(['password' => 'too_short'], self::json($weak)['errors']);
        $finished = $this->admin('POST', '/admin-api/v1/enrolment', ['token' => $token, 'password' => self::PASSWORD, 'code' => $this->totp($ada, -1)]);
        self::assertSame(200, $finished->status());
        self::assertIsArray(self::json($finished)['recovery_codes']);
        self::assertCount(10, self::json($finished)['recovery_codes']);
        self::assertSame(400, $this->admin('POST', '/admin-api/v1/enrolment/start', ['token' => $token])->status(), 'the invitation is used up');

        $signedIn = $this->signInWithCodes('ada@example.test', $ada, 0);
        $cookie = $this->staffCookie($signedIn);
        $csrf = self::json($signedIn)['csrf_token'] ?? null;
        self::assertIsString($csrf);

        $session = $this->admin('GET', '/admin-api/v1/auth/session', null, $cookie);
        self::assertSame(200, $session->status());
        $staff = self::json($session)['staff'] ?? null;
        self::assertIsArray($staff);
        self::assertSame('Ada Lovelace', $staff['full_name']);
        self::assertSame(['security_admin'], $staff['roles']);
        self::assertSame(['password' => true, 'passkeys' => 0], $staff['sign_in']);
        self::assertIsArray($staff['permissions']);
        self::assertContains('staff.manage', $staff['permissions']);
        self::assertNotContains('settings.manage', $staff['permissions']);

        // Writes need the CSRF token.
        self::assertSame(403, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie)->status());
        self::assertSame(201, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie, $csrf)->status());
        self::assertSame(409, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'bob@example.test', 'full_name' => 'Bob', 'role' => 'support'], $cookie, $csrf)->status());
        self::assertSame(422, $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'eve@example.test', 'full_name' => 'Eve', 'role' => 'super_admin'], $cookie, $csrf)->status());

        // An optional passkey: a current authenticator code first, then it signs in on its own.
        self::assertSame(401, $this->admin('POST', '/admin-api/v1/account/passkeys/options', ['code' => '000000'], $cookie, $csrf)->status());
        $key = new SoftwareAuthenticator(self::ADMIN);
        $options = self::json($this->admin('POST', '/admin-api/v1/account/passkeys/options', ['code' => $this->totp($ada, 1)], $cookie, $csrf));
        self::assertIsString($options['ceremony_id'] ?? null);
        self::assertIsArray($options['options'] ?? null);
        /** @var array<string, mixed> $creation */
        $creation = $options['options'];
        $credential = json_decode($key->register($creation), true, 16, JSON_THROW_ON_ERROR);
        $added = $this->admin('POST', '/admin-api/v1/account/passkeys', ['ceremony_id' => $options['ceremony_id'], 'credential' => $credential, 'label' => 'Laptop'], $cookie, $csrf);
        self::assertSame(['passkeys' => 1], self::json($added));
        self::assertSame(200, $this->signInWithPasskey($key)->status());

        // The password-sign-in setup is only for members without a password.
        self::assertSame(409, $this->admin('POST', '/admin-api/v1/account/sign-in-setup/start', [], $cookie, $csrf)->status());

        self::assertSame(204, $this->admin('POST', '/admin-api/v1/auth/sign-out', [], $cookie, $csrf)->status());
        self::assertSame(401, $this->admin('GET', '/admin-api/v1/auth/session', null, $cookie)->status());
    }

    /** §6.20: each step needs the ticket cookie from the step before, and failures say nothing more. */
    public function testCodeSignInStepsNeedTheirTicket(): void
    {
        $ada = $this->activateAda();

        $wrong = $this->admin('POST', '/admin-api/v1/auth/password', ['email' => 'ada@example.test', 'password' => 'not the right password']);
        self::assertSame(401, $wrong->status());
        self::assertStringContainsString('sign_in_failed', $wrong->body());
        $unknown = $this->admin('POST', '/admin-api/v1/auth/password', ['email' => 'nobody@example.test', 'password' => self::PASSWORD]);
        self::assertSame(401, $unknown->status());
        self::assertSame(self::json($wrong)['code'], self::json($unknown)['code']);
        self::assertNull($this->cookie($wrong, StaffSessionCookie::NAME));

        // No ticket, no code step; a ticket for one step does not work at the next.
        self::assertSame('sign_in_expired', self::json($this->admin('POST', '/admin-api/v1/auth/authenticator-code', ['code' => $this->totp($ada, 0)]))['code']);
        $first = $this->admin('POST', '/admin-api/v1/auth/password', ['email' => 'ada@example.test', 'password' => self::PASSWORD]);
        self::assertSame(['status' => 'code_required', 'next' => 'totp', 'expires_in' => 600], self::json($first));
        $ticket = $this->cookie($first, StaffStepCookie::NAME);
        self::assertNotNull($ticket);
        self::assertSame('sign_in_expired', self::json($this->admin('POST', '/admin-api/v1/auth/email-code', ['code' => '123456'], $ticket))['code']);
        self::assertSame('sign_in_expired', self::json($this->admin('POST', '/admin-api/v1/auth/authenticator-code', ['code' => $this->totp($ada, 0)], $ticket))['code'], 'the failed attempt used the ticket');

        // A wrong code answers with a new ticket; the email arrives only after a right one.
        $ticket = $this->cookie($this->admin('POST', '/admin-api/v1/auth/password', ['email' => 'ada@example.test', 'password' => self::PASSWORD]), StaffStepCookie::NAME);
        $miss = $this->admin('POST', '/admin-api/v1/auth/authenticator-code', ['code' => '000000'], (string) $ticket);
        self::assertSame('code_invalid', self::json($miss)['code']);
        $ticket = $this->cookie($miss, StaffStepCookie::NAME);
        self::assertNotNull($ticket);
        self::assertSame('0', $this->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'staff-sign-in-code'"));
        $next = $this->admin('POST', '/admin-api/v1/auth/authenticator-code', ['code' => $this->totp($ada, 0)], $ticket);
        self::assertSame('email', self::json($next)['next']);
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'staff-sign-in-code'"));
        $resend = $this->admin('POST', '/admin-api/v1/auth/email-code/resend', [], (string) $this->cookie($next, StaffStepCookie::NAME));
        self::assertSame(429, $resend->status());
        self::assertNotNull($resend->header('retry-after'));
        $done = $this->admin('POST', '/admin-api/v1/auth/email-code', ['code' => $this->latestParameter('staff-sign-in-code', 'code')], (string) $this->cookie($resend, StaffStepCookie::NAME));
        self::assertSame(200, $done->status());
        self::assertNotNull($this->cookie($done, StaffSessionCookie::NAME));
        self::assertSame('', $this->cookie($done, StaffStepCookie::NAME), 'the ticket cookie is cleared');
    }

    public function testSupportStaffCannotManageStaff(): void
    {
        $ada = $this->activateAda();
        $adaIn = $this->signInWithCodes('ada@example.test', $ada, 0);
        $adaCookie = $this->staffCookie($adaIn);
        $csrf = self::json($adaIn)['csrf_token'] ?? '';
        self::assertIsString($csrf);
        $this->admin('POST', '/admin-api/v1/staff/invitations', ['email' => 'sam@example.test', 'full_name' => 'Sam', 'role' => 'support'], $adaCookie, $csrf);
        $samToken = $this->latestParameter('staff-invitation', 'token');
        $this->admin('POST', '/admin-api/v1/enrolment/start', ['token' => $samToken]);
        $sam = $this->scalar("SELECT id FROM staff_members WHERE full_name = 'Sam'");
        self::assertSame(200, $this->admin('POST', '/admin-api/v1/enrolment', ['token' => $samToken, 'password' => self::PASSWORD, 'code' => $this->totp($sam, -1)])->status());
        // Ada (a staff manager) is told that Sam can now sign in.
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'staff-activated'"));

        $samCookie = $this->staffCookie($this->signInWithCodes('sam@example.test', $sam, 0));
        self::assertSame(403, $this->admin('GET', '/admin-api/v1/staff', null, $samCookie)->status());
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM audit_events WHERE event_type = 'staff.permission_denied'"));
    }

    public function testFailedPasskeySignInIsGeneric(): void
    {
        $this->activateAda();
        $response = $this->signInWithPasskey(new SoftwareAuthenticator(self::ADMIN));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('sign_in_failed', $response->body());
        self::assertNull($response->header('set-cookie'));
    }

    /** D-6, T-31: the admin API is invisible on the customer host, and the two cookies never cross. */
    public function testAdminAndCustomerSurfacesStaySeparate(): void
    {
        $ada = $this->activateAda();
        $staffCookie = $this->staffCookie($this->signInWithCodes('ada@example.test', $ada, 0));

        self::assertSame(404, $this->send('GET', '/admin-api/v1/auth/session', '', ['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_COOKIE' => $staffCookie])->status());
        self::assertSame(401, $this->send('GET', '/api/v1/auth/session', '', ['HTTP_HOST' => self::ADMIN_HOST, 'HTTP_COOKIE' => $staffCookie])->status());
        self::assertSame(401, $this->admin('GET', '/admin-api/v1/auth/session', null, SessionCookie::NAME . '=' . explode('=', $staffCookie, 2)[1])->status());
        self::assertSame(403, $this->send('POST', '/admin-api/v1/auth/password', '{}', ['HTTP_HOST' => self::ADMIN_HOST, 'HTTP_ORIGIN' => 'http://127.0.0.1:8080'])->status());
    }

    private function activateAda(): string
    {
        $token = $this->latestParameter('staff-invitation', 'token');
        $this->admin('POST', '/admin-api/v1/enrolment/start', ['token' => $token]);
        $ada = $this->scalar('SELECT id FROM staff_members');
        self::assertSame(200, $this->admin('POST', '/admin-api/v1/enrolment', ['token' => $token, 'password' => self::PASSWORD, 'code' => $this->totp($ada, -1)])->status());

        return $ada;
    }

    private function signInWithCodes(string $email, string $staffId, int $totpOffset): HttpResponse
    {
        $password = $this->admin('POST', '/admin-api/v1/auth/password', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(200, $password->status(), $password->body());
        $code = $this->admin('POST', '/admin-api/v1/auth/authenticator-code', ['code' => $this->totp($staffId, $totpOffset)], (string) $this->cookie($password, StaffStepCookie::NAME));
        self::assertSame(200, $code->status(), $code->body());
        $done = $this->admin('POST', '/admin-api/v1/auth/email-code', ['code' => $this->latestParameter('staff-sign-in-code', 'code')], (string) $this->cookie($code, StaffStepCookie::NAME));
        self::assertSame(200, $done->status(), $done->body());

        return $done;
    }

    private function signInWithPasskey(SoftwareAuthenticator $authenticator): HttpResponse
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

    /**
     * What the member's authenticator app shows, at a step offset from now. Each
     * code works once per step, so a test walks previous → current → next.
     */
    private function totp(string $staffId, int $offset): string
    {
        $ring = KeyRing::fromNamedProcessEnvironment('MFA_SECRET_KEYS', 'MFA_SECRET_ACTIVE_KEY') ?? throw new \LogicException('MFA keys');
        $secret = (new SecretCipher($ring))->decrypt($this->scalar('SELECT secret_envelope FROM staff_totp WHERE staff_id = ?', [$staffId]), StaffSecondFactor::associatedData($staffId));

        return Totp::code($secret, Totp::step(new \DateTimeImmutable()) + $offset);
    }

    /** The value of one cookie the response sets, '' when it clears it, null when it does not set it. */
    private function cookie(HttpResponse $response, string $name): ?string
    {
        $values = $response->headers()['set-cookie'] ?? [];
        foreach ($values as $header) {
            if (str_starts_with($header, $name . '=')) {
                $value = substr(explode(';', $header)[0], strlen($name) + 1);

                return $value === '' ? '' : $name . '=' . $value;
            }
        }

        return null;
    }

    private function staffCookie(HttpResponse $response): string
    {
        foreach ($response->headers()['set-cookie'] ?? [] as $header) {
            if (str_starts_with($header, StaffSessionCookie::NAME . '=')) {
                self::assertStringContainsString('SameSite=Strict', $header);

                return explode(';', $header)[0];
            }
        }
        self::fail('No staff session cookie.');
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

    private function latestParameter(string $template, string $name): string
    {
        $sealed = $this->scalar('SELECT payload FROM email_outbox WHERE template = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$template]);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $parameters = $payload['parameters'] ?? null;
        self::assertIsArray($parameters);
        self::assertIsString($parameters[$name] ?? null);

        return $parameters[$name];
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
