<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Session\RedisSignInThrottle;

/**
 * Sign-in, session and sign-out through the real HTTP stack, MySQL 8.4 and
 * Redis 7 (SRS IAM-005, IAM-006, IAM-009; threat model §6.11, T-07..T-09,
 * T-18, T-20, T-21, D-3, D-6).
 */
final class SignInIntegrationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';

    private OutboxSealer $opener;
    private RedisClient $redis;
    private string $origin;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $pair = OutboxSealer::generateKeyPair();
        putenv('EMAIL_OUTBOX_PUBLIC_KEY=' . $pair['public']);
        $this->opener = OutboxSealer::forOpening($pair['public'], $pair['secret']);

        $app = AppFactory::create(self::basePath());
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator(Resolve::get($app->container(), Connection::class), $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->redis = Resolve::get($app->container(), RedisClient::class);
        $url = Resolve::get($app->container(), Configuration::class)->get('app.url');
        self::assertIsString($url);
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        // A fresh /24 per test keeps the Redis windows and locks apart.
        $this->ip = '198.' . random_int(18, 19) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        putenv('EMAIL_OUTBOX_PUBLIC_KEY');
        parent::tearDown();
    }

    public function testSignInSessionAndSignOutRoundTrip(): void
    {
        $email = $this->activeCustomer();

        $signedIn = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(200, $signedIn->status());
        self::assertSame('no-store', $signedIn->header('cache-control'));
        $cookie = (string) (((array) ($signedIn->headers()['set-cookie'] ?? []))[0] ?? '');
        self::assertMatchesRegularExpression('/^__Host-pc_sid=[A-Za-z0-9_-]{43}; Path=\/; Secure; HttpOnly; SameSite=Lax$/', $cookie);
        $sessionId = substr(explode(';', $cookie)[0], strlen(SessionCookie::NAME) + 1);
        $csrf = self::json($signedIn)['csrf_token'] ?? null;
        self::assertIsString($csrf);

        // T-20: Redis holds the session under the ID's hash only.
        self::assertTrue($this->redis->exists('pc:sess:' . hash('sha256', $sessionId)));
        self::assertFalse($this->redis->exists('pc:sess:' . $sessionId));
        self::assertStringNotContainsString($sessionId, (string) $this->redis->get('pc:sess:' . hash('sha256', $sessionId)));

        $session = $this->get('/api/v1/auth/session', $sessionId);
        self::assertSame(200, $session->status());
        self::assertSame(['identity' => ['full_name' => 'Ada Okafor', 'email' => $email], 'csrf_token' => $csrf], self::json($session));

        // T-21: sign-out needs the session's own CSRF token.
        self::assertSame(403, $this->post('/api/v1/auth/sign-out', [], $sessionId)->status());
        self::assertSame(403, $this->post('/api/v1/auth/sign-out', [], $sessionId, ['HTTP_X_CSRF_TOKEN' => str_repeat('A', 43)])->status());
        self::assertSame(200, $this->get('/api/v1/auth/session', $sessionId)->status(), 'Still signed in');

        $out = $this->post('/api/v1/auth/sign-out', [], $sessionId, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertSame(204, $out->status());
        self::assertStringContainsString('Max-Age=0', (string) $out->header('set-cookie'));
        self::assertFalse($this->redis->exists('pc:sess:' . hash('sha256', $sessionId)));
        self::assertSame(401, $this->get('/api/v1/auth/session', $sessionId)->status());
    }

    public function testWrongPasswordAndUnknownEmailGetTheSameResponse(): void
    {
        $email = $this->activeCustomer();

        $wrong = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => 'not the password']);
        $unknown = $this->post('/api/v1/auth/sign-in', ['email' => 'nobody.' . bin2hex(random_bytes(3)) . '@example.ng', 'password' => self::PASSWORD]);

        self::assertSame(401, $wrong->status());
        self::assertStringContainsString('"code":"invalid_credentials"', $wrong->body());
        self::assertSame(self::withoutRequestId($wrong), self::withoutRequestId($unknown), 'T-09');
        self::assertNull($wrong->header('set-cookie'));
    }

    public function testUnverifiedCustomerIsToldOnlyAfterTheCorrectPassword(): void
    {
        $email = self::uniqueEmail();
        $this->post('/api/v1/auth/register', self::form($email));

        self::assertSame(401, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => 'not the password'])->status());
        $pending = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(403, $pending->status());
        self::assertStringContainsString('"code":"email_not_verified"', $pending->body());
        self::assertNull($pending->header('set-cookie'));
    }

    public function testFifthFailureLocksTheEmailFromThisNetworkWithRetryAfter(): void
    {
        $email = $this->activeCustomer();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => 'wrong ' . $i])->status());
        }

        $locked = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(429, $locked->status());
        $retry = (int) $locked->header('retry-after');
        self::assertGreaterThan(0, $retry);
        self::assertLessThanOrEqual(RedisSignInThrottle::BASE_LOCK_SECONDS, $retry);

        // T-08: the owner on another network signs in normally.
        $this->ip = '203.0.' . random_int(0, 255) . '.9';
        self::assertSame(200, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD])->status());
    }

    public function testLockDoublesAndIsCappedAtFifteenMinutes(): void
    {
        $throttle = new RedisSignInThrottle($this->redis);
        $pair = hash('sha256', random_bytes(16));

        $locks = [];
        for ($i = 0; $i < 12; $i++) {
            $locks[] = $throttle->recordFailure($pair);
        }

        self::assertSame([0, 0, 0, 0, 30, 60, 120, 240, 480, 900, 900, 900], $locks);
        self::assertGreaterThan(890, $throttle->retryAfter($pair));
        $throttle->clear($pair);
        self::assertSame(0, $throttle->retryAfter($pair));
    }

    public function testOldHashIsUpgradedToTheCurrentParametersOnSignIn(): void
    {
        $email = $this->activeCustomer();
        $old = password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 19_456, 'time_cost' => 2, 'threads' => 1]);
        self::pdo()->prepare('UPDATE identities SET password_hash = :h')->execute(['h' => $old]);

        self::assertSame(200, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD])->status());

        $statement = self::pdo()->query('SELECT password_hash FROM identities');
        self::assertNotFalse($statement);
        $stored = $statement->fetchColumn();
        self::assertIsString($stored);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=1$', $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored));
    }

    public function testSigningInAgainReplacesTheSessionSentWithTheRequest(): void
    {
        $email = $this->activeCustomer();
        $first = $this->sessionIdOf($this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]));
        $second = $this->sessionIdOf($this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD], $first));

        self::assertNotSame($first, $second);
        self::assertSame(401, $this->get('/api/v1/auth/session', $first)->status(), 'T-18');
        self::assertSame(200, $this->get('/api/v1/auth/session', $second)->status());
    }

    public function testSuspendedCustomerLosesTheirSession(): void
    {
        $email = $this->activeCustomer();
        $sid = $this->sessionIdOf($this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]));
        self::pdo()->exec("UPDATE identities SET status = 'suspended'");

        $response = $this->get('/api/v1/auth/session', $sid);
        self::assertSame(401, $response->status());
        self::assertStringContainsString('Max-Age=0', (string) $response->header('set-cookie'));
        self::assertFalse($this->redis->exists('pc:sess:' . hash('sha256', $sid)));
        self::assertSame(403, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD])->status());
    }

    public function testCrossOriginSignInIsRefused(): void
    {
        $email = $this->activeCustomer();

        self::assertSame(403, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD], null, ['HTTP_ORIGIN' => 'https://evil.example'])->status());
        self::assertSame(422, $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD, 'remember' => true])->status());
    }

    public function testChallengeConfigIsPublicAndOffWithoutKeys(): void
    {
        $response = $this->get('/api/v1/auth/challenge', null);

        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->header('cache-control'));
        self::assertSame(['turnstile_site_key' => null], self::json($response));
    }

    private function activeCustomer(): string
    {
        $email = self::uniqueEmail();
        self::assertSame(202, $this->post('/api/v1/auth/register', self::form($email))->status());
        self::assertSame(200, $this->post('/api/v1/auth/verify-email', ['token' => $this->queuedToken()])->status());

        return $email;
    }

    /** @return array<string, mixed> */
    private static function form(string $email): array
    {
        return ['full_name' => 'Ada Okafor', 'email' => $email, 'phone' => '+2348031234567', 'password' => self::PASSWORD, 'accept_terms' => true];
    }

    private static function uniqueEmail(): string
    {
        return 'ada.' . bin2hex(random_bytes(4)) . '@example.ng';
    }

    private function queuedToken(): string
    {
        $statement = self::pdo()->query("SELECT payload FROM email_outbox WHERE template = 'verify-email' ORDER BY created_at DESC LIMIT 1");
        self::assertNotFalse($statement);
        $sealed = $statement->fetchColumn();
        self::assertIsString($sealed);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['parameters'] ?? null);
        $token = $payload['parameters']['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function sessionIdOf(HttpResponse $response): string
    {
        self::assertSame(200, $response->status());

        return substr(explode(';', (string) $response->header('set-cookie'))[0], strlen(SessionCookie::NAME) + 1);
    }

    /** @return array<array-key, mixed> */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{int, array<array-key, mixed>} */
    private static function withoutRequestId(HttpResponse $response): array
    {
        $data = self::json($response);
        unset($data['request_id']);

        return [$response->status(), $data];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $server
     */
    private function post(string $path, array $data, ?string $sessionId = null, array $server = []): HttpResponse
    {
        return $this->send('POST', $path, json_encode((object) $data, JSON_THROW_ON_ERROR), $sessionId, $server);
    }

    private function get(string $path, ?string $sessionId): HttpResponse
    {
        return $this->send('GET', $path, '', $sessionId, []);
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $path, string $body, ?string $sessionId, array $server): HttpResponse
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $body);
        rewind($stream);
        $defaults = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTP_ORIGIN' => $this->origin,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ];
        if ($sessionId !== null) {
            $defaults['HTTP_COOKIE'] = SessionCookie::NAME . '=' . $sessionId;
        }
        $request = (new RequestFactory())->fromGlobals($server + $defaults, [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }
}
