<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

/**
 * Registration and email verification through the real HTTP stack, MySQL 8.4
 * and Redis 7 (SRS IAM-001, IAM-002; threat model T-01..T-04, T-46..T-48, D-2, D-3).
 */
final class RegistrationIntegrationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';

    private OutboxSealer $opener;
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
        $url = Resolve::get($app->container(), Configuration::class)->get('app.url');
        self::assertIsString($url);
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        // A fresh client IP per test keeps the Redis rate-limit windows apart.
        $this->ip = '198.51.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        putenv('EMAIL_OUTBOX_PUBLIC_KEY');
        parent::tearDown();
    }

    public function testRegisterThenVerifyActivatesTheIdentityOnce(): void
    {
        $email = self::uniqueEmail();
        $response = $this->post('/api/v1/auth/register', self::form($email));

        self::assertSame(202, $response->status());
        self::assertSame('{"status":"check_your_email"}', $response->body());
        self::assertSame('no-store', $response->header('cache-control'));

        $identity = self::one('SELECT * FROM identities');
        self::assertSame('pending_verification', $identity['status']);
        self::assertNull($identity['verified_at']);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=1$', self::scalar('SELECT password_hash FROM identities', 'password_hash'));
        self::assertTrue(password_verify(self::PASSWORD, self::scalar('SELECT password_hash FROM identities', 'password_hash')));
        $member = self::one('SELECT m.role, a.type FROM account_members m JOIN accounts a ON a.id = m.account_id WHERE m.identity_id = :id', ['id' => self::scalar('SELECT id FROM identities', 'id')]);
        self::assertSame(['role' => 'owner', 'type' => 'personal'], $member);

        $token = $this->queuedToken();
        $dump = json_encode([self::rows('SELECT * FROM email_verification_tokens'), self::rows('SELECT * FROM email_outbox')], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token, $dump, 'D-2: the raw token is stored nowhere');
        self::assertSame(hash('sha256', $token), self::one('SELECT token_hash FROM email_verification_tokens')['token_hash']);

        $verified = $this->post('/api/v1/auth/verify-email', ['token' => $token]);
        self::assertSame(200, $verified->status());
        self::assertSame('{"status":"verified"}', $verified->body());
        $active = self::one('SELECT status, verified_at FROM identities');
        self::assertSame('active', $active['status']);
        self::assertNotNull($active['verified_at']);

        $again = $this->post('/api/v1/auth/verify-email', ['token' => $token]);
        self::assertSame(400, $again->status());
        self::assertStringContainsString('"code":"verification_link_invalid"', $again->body());
        self::assertStringContainsString('already been used or has expired', $again->body());
    }

    public function testExistingAccountGetsTheSameResponseAndAnAccountExistsEmail(): void
    {
        $email = self::uniqueEmail();
        $first = $this->post('/api/v1/auth/register', self::form($email));
        $this->post('/api/v1/auth/verify-email', ['token' => $this->queuedToken()]);

        $second = $this->post('/api/v1/auth/register', ['password' => 'another long passphrase'] + self::form($email));

        self::assertSame([$first->status(), $first->body()], [$second->status(), $second->body()], 'T-01: no enumeration');
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM identities'));
        self::assertTrue(password_verify(self::PASSWORD, self::scalar('SELECT password_hash FROM identities', 'password_hash')), 'The active password is untouched');
        self::assertSame('account-exists', self::one('SELECT template FROM email_outbox ORDER BY created_at DESC LIMIT 1')['template']);
    }

    public function testPendingRegistrationIsReplacedAndOnlyTheNewestLinkWorks(): void
    {
        $email = self::uniqueEmail();
        $this->post('/api/v1/auth/register', ['password' => 'attacker passphrase'] + self::form($email));
        $attackerToken = $this->queuedToken();
        $this->post('/api/v1/auth/register', self::form($email));
        $victimToken = $this->queuedToken();

        self::assertSame(400, $this->post('/api/v1/auth/verify-email', ['token' => $attackerToken])->status(), 'T-46: the older link is dead');
        self::assertSame(200, $this->post('/api/v1/auth/verify-email', ['token' => $victimToken])->status());
        self::assertTrue(password_verify(self::PASSWORD, self::scalar('SELECT password_hash FROM identities', 'password_hash')));
        self::assertSame(0, self::rowCount('SELECT COUNT(*) AS n FROM email_verification_tokens WHERE used_at IS NULL'));
    }

    public function testPerIpLimitReturns429WithRetryAfter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(202, $this->post('/api/v1/auth/register', self::form(self::uniqueEmail()))->status());
        }

        $blocked = $this->post('/api/v1/auth/register', self::form(self::uniqueEmail()));

        self::assertSame(429, $blocked->status());
        self::assertStringContainsString('"code":"too_many_attempts"', $blocked->body());
        self::assertGreaterThan(0, (int) $blocked->header('retry-after'));
        self::assertSame(5, self::rowCount('SELECT COUNT(*) AS n FROM identities'));
    }

    public function testMassAssignmentAndBadInputAreRejectedWithFieldCodesOnly(): void
    {
        $response = $this->post('/api/v1/auth/register', ['role' => 'admin', 'password' => 'Zq7tinyPw'] + self::form(self::uniqueEmail()));

        self::assertSame(422, $response->status());
        $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['role' => 'unknown_field', 'password' => 'too_short'], $body['errors'] ?? null);
        self::assertStringNotContainsString('Zq7tinyPw', $response->body(), 'Submitted values are never echoed');
        self::assertStringNotContainsString('admin', $response->body());
        self::assertSame(0, self::rowCount('SELECT COUNT(*) AS n FROM identities'));
    }

    public function testCrossOriginAndNonJsonWritesAreRefused(): void
    {
        $form = self::form(self::uniqueEmail());
        self::assertSame(403, $this->post('/api/v1/auth/register', $form, ['HTTP_ORIGIN' => 'https://evil.example'])->status());
        self::assertSame(415, $this->post('/api/v1/auth/register', $form, ['CONTENT_TYPE' => 'text/plain'])->status());
        self::assertSame(400, $this->raw('/api/v1/auth/register', '[1,2]')->status());
        self::assertSame(0, self::rowCount('SELECT COUNT(*) AS n FROM identities'));
    }

    public function testTheDatabaseRefusesNonArgon2idHashesAndActiveWithoutVerification(): void
    {
        $this->post('/api/v1/auth/register', self::form(self::uniqueEmail()));
        $pdo = self::pdo();

        foreach ([
            "UPDATE identities SET password_hash = '\$2y\$10\$abcdefghijklmnopqrstuv'",
            "UPDATE identities SET status = 'active'",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('The database accepted: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('3819', $e->getMessage(), 'CHECK constraint violation');
            }
        }
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
        $sealed = self::scalar("SELECT payload FROM email_outbox WHERE template = 'verify-email' ORDER BY created_at DESC LIMIT 1", 'payload');
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['parameters'] ?? null);
        $token = $payload['parameters']['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $server
     */
    private function post(string $path, array $data, array $server = []): HttpResponse
    {
        return $this->raw($path, json_encode($data, JSON_THROW_ON_ERROR), $server);
    }

    /** @param array<string, string> $server */
    private function raw(string $path, string $body, array $server = []): HttpResponse
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $body);
        rewind($stream);
        $request = (new RequestFactory())->fromGlobals($server + [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'HTTP_ORIGIN' => $this->origin,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ], [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }

    private static function rowCount(string $sql): int
    {
        return (int) self::scalar($sql, 'n');
    }

    /** @param array<string, string> $params */
    private static function scalar(string $sql, string $column, array $params = []): string
    {
        $value = self::one($sql, $params)[$column] ?? null;
        self::assertTrue(is_string($value) || is_int($value), $column . ' is not a scalar');

        return (string) $value;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private static function one(string $sql, array $params = []): array
    {
        $rows = self::rows($sql, $params);
        self::assertNotEmpty($rows, 'No row for: ' . $sql);

        return $rows[0];
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private static function rows(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }
}
