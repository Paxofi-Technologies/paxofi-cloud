<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Cli\AuditVerifyCommand;
use PaxofiCloud\Cli\Console;
use PaxofiCloud\Http\DeviceCookie;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditChain;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

/**
 * The security audit log through the real HTTP stack, MySQL 8.4 and Redis 7
 * (SRS AUD-001, AUD-002, AUD-004; threat model D-14, T-39, T-50..T-52, §6.14).
 */
final class AuditIntegrationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';
    private const string USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

    private OutboxSealer $opener;
    private MysqlAuditChain $chain;
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
        $connection = Resolve::get($app->container(), Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->chain = new MysqlAuditChain($connection, $repository);
        $url = Resolve::get($app->container(), Configuration::class)->get('app.url');
        self::assertIsString($url);
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->ip = '198.' . random_int(18, 19) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        putenv('EMAIL_OUTBOX_PUBLIC_KEY');
        parent::tearDown();
    }

    public function testTheCustomerSeesTheirOwnActivityWithWhereAndOnWhat(): void
    {
        $email = $this->activeCustomer();
        self::assertSame(401, $this->signIn($email, 'not the password')->status());
        $sid = $this->sessionFrom($this->signIn($email, self::PASSWORD));

        $activity = self::json($this->get('/api/v1/account/activity', $sid));
        self::assertIsArray($activity['events']);
        $types = array_map(static fn (mixed $e): mixed => is_array($e) ? $e['type'] : null, $activity['events']);
        self::assertSame(['auth.sign_in.new_device', 'auth.sign_in.succeeded', 'auth.sign_in.failed', 'identity.email_verified', 'identity.registered'], $types, 'newest first');

        $latest = $activity['events'][0];
        self::assertIsArray($latest);
        self::assertSame($this->ip, $latest['ip']);
        self::assertSame(['country' => 'NG', 'region' => 'Lagos', 'city' => 'Ikeja'], $latest['location']);
        self::assertIsArray($latest['device']);
        self::assertSame('Safari 17 on iOS 17.6 (mobile)', $latest['device']['label']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', is_string($latest['occurred_at']) ? $latest['occurred_at'] : '');
        $failed = $activity['events'][2];
        self::assertIsArray($failed);
        self::assertSame(['failure', ['reason' => 'wrong_password']], [$failed['outcome'], $failed['details']]);
    }

    public function testActivityNeedsASessionAndShowsOnlyTheSessionsOwnEvents(): void
    {
        self::assertSame(401, $this->get('/api/v1/account/activity', '')->status());
        $first = $this->activeCustomer();
        $second = $this->activeCustomer();
        $this->signIn($first, self::PASSWORD);
        $sid = $this->sessionFrom($this->signIn($second, self::PASSWORD));

        $events = self::json($this->get('/api/v1/account/activity', $sid))['events'];
        self::assertIsArray($events);
        self::assertCount(4, $events, 'T-52: registered, verified, signed in, new device — the second customer only');
    }

    public function testTheChainVerifiesAndAnyEditIsDetected(): void
    {
        $this->signIn($this->activeCustomer(), self::PASSWORD);
        $ok = $this->chain->verify();
        self::assertTrue($ok->ok());
        self::assertSame(4, $ok->events);
        self::assertSame(self::query('SELECT hash FROM audit_chain_head')->fetchColumn(), $ok->headHash);

        self::pdo()->exec("UPDATE audit_events SET outcome = 'failure' WHERE seq = 2");
        $broken = $this->chain->verify();
        self::assertSame([2, 'event content changed'], [$broken->brokenAt, $broken->reason]);
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        self::assertSame(2, (new AuditVerifyCommand($this->chain, new Console($out, $err)))->execute([]));
    }

    public function testDeletedEventsAndEditedContextAreDetected(): void
    {
        $this->signIn($this->activeCustomer(), self::PASSWORD);

        self::pdo()->exec("UPDATE audit_event_context SET city = 'Abuja' WHERE event_seq = 3");
        self::assertSame([3, 'request context changed'], [$this->chain->verify()->brokenAt, $this->chain->verify()->reason]);
        self::pdo()->exec("UPDATE audit_event_context SET city = 'Ikeja' WHERE event_seq = 3");
        self::assertTrue($this->chain->verify()->ok());

        self::pdo()->exec('DELETE FROM audit_event_context WHERE event_seq = 2');
        self::pdo()->exec('DELETE FROM audit_events WHERE seq = 2');
        self::assertSame([2, 'missing sequence number'], [$this->chain->verify()->brokenAt, $this->chain->verify()->reason]);
    }

    public function testPurgingOldContextKeepsTheChainValidAndTheEvents(): void
    {
        $email = $this->activeCustomer();
        self::pdo()->exec("UPDATE audit_event_context SET created_at = '2020-01-01 00:00:00'");
        $this->signIn($email, self::PASSWORD);

        self::assertSame(2, $this->chain->purgeContext(new \DateTimeImmutable('-180 days')));
        $result = $this->chain->verify();
        self::assertTrue($result->ok());
        self::assertSame([4, 2], [$result->events, $result->contextsPurged]);
        self::assertSame(2, (int) self::query('SELECT COUNT(*) FROM audit_event_context')->fetchColumn());
    }

    public function testNothingPersonalOrSecretIsStoredInEvents(): void
    {
        $email = $this->activeCustomer();
        $this->signIn($email, 'not the password');
        $this->signIn($email, self::PASSWORD);

        $dump = json_encode(self::query('SELECT * FROM audit_events')->fetchAll(\PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        foreach ([$email, self::PASSWORD, 'not the password', 'Ada', $this->ip] as $secret) {
            self::assertStringNotContainsString($secret, $dump, 'T-51');
        }
    }

    public function testEverySignInQueuesOneAlertAndANewDeviceIsCalledOut(): void
    {
        $email = $this->activeCustomer();
        $first = $this->signIn($email, self::PASSWORD);
        $device = self::cookie($first, DeviceCookie::NAME);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $device);
        self::assertStringContainsString('Max-Age=34560000; Secure; HttpOnly; SameSite=Lax', implode("\n", (array) ($first->headers()['set-cookie'] ?? [])));

        $again = $this->send('POST', '/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD], ['HTTP_COOKIE' => DeviceCookie::NAME . '=' . $device]);
        self::assertSame($device, self::cookie($again, DeviceCookie::NAME), 'the same device keeps its token');
        self::assertSame(401, $this->signIn($email, 'not the password')->status());

        self::assertSame(['new-device-alert', 'sign-in-alert'], $this->alerts(), 'one email per successful sign-in, none for a failed one');
        $alert = $this->latestPayload('new-device-alert');
        self::assertSame($email, $alert['to']);
        self::assertSame(['name' => 'Ada Okafor', 'device' => 'Safari 17 on iOS 17.6 (mobile)', 'location' => 'Ikeja, Lagos, Nigeria', 'ip' => $this->ip], array_diff_key($alert['parameters'], ['time' => 1]));
        self::assertSame([hash('sha256', $device)], self::query('SELECT device_hash FROM identity_devices')->fetchAll(\PDO::FETCH_COLUMN), 'T-55: only the hash');
    }

    /** @return list<mixed> alert templates queued, oldest first */
    private function alerts(): array
    {
        return array_values(self::query("SELECT template FROM email_outbox WHERE template LIKE '%alert' ORDER BY created_at, id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{to: string, parameters: array<string, string>} */
    private function latestPayload(string $template): array
    {
        $statement = self::pdo()->prepare('SELECT payload FROM email_outbox WHERE template = :template ORDER BY created_at DESC LIMIT 1');
        $statement->execute(['template' => $template]);
        $sealed = $statement->fetchColumn();
        self::assertIsString($sealed);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array{to: string, parameters: array<string, string>} $payload */
        return $payload;
    }

    private static function cookie(HttpResponse $response, string $name): string
    {
        foreach ((array) ($response->headers()['set-cookie'] ?? []) as $cookie) {
            if (str_starts_with($cookie, $name . '=')) {
                return substr(explode(';', $cookie)[0], strlen($name) + 1);
            }
        }
        self::fail('No ' . $name . ' cookie set');
    }

    private static function query(string $sql): \PDOStatement
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement;
    }

    private function activeCustomer(): string
    {
        $email = 'ada.' . bin2hex(random_bytes(4)) . '@example.ng';
        $form = ['full_name' => 'Ada Okafor', 'email' => $email, 'phone' => '+2348031234567', 'password' => self::PASSWORD, 'accept_terms' => true];
        self::assertSame(202, $this->send('POST', '/api/v1/auth/register', $form, [])->status());
        $sealed = self::query("SELECT payload FROM email_outbox WHERE template = 'verify-email' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        self::assertIsString($sealed);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['parameters'] ?? null);
        self::assertSame(200, $this->send('POST', '/api/v1/auth/verify-email', ['token' => $payload['parameters']['token'] ?? ''], [])->status());

        return $email;
    }

    private function signIn(string $email, string $password): HttpResponse
    {
        return $this->send('POST', '/api/v1/auth/sign-in', ['email' => $email, 'password' => $password], []);
    }

    private function sessionFrom(HttpResponse $response): string
    {
        self::assertSame(200, $response->status());

        return self::cookie($response, SessionCookie::NAME);
    }

    private function get(string $path, string $sid): HttpResponse
    {
        return $this->send('GET', $path, null, $sid === '' ? [] : ['HTTP_COOKIE' => SessionCookie::NAME . '=' . $sid]);
    }

    /** @return array<array-key, mixed> */
    private static function json(HttpResponse $response): array
    {
        self::assertSame(200, $response->status(), $response->body());
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Every request arrives as through Cloudflare: client IP, visitor-location headers and a phone's user agent.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string> $server
     */
    private function send(string $method, string $path, ?array $data, array $server): HttpResponse
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $data === null ? '' : json_encode((object) $data, JSON_THROW_ON_ERROR));
        rewind($stream);
        $request = (new RequestFactory())->fromGlobals($server + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTP_ORIGIN' => $this->origin,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
            'HTTP_USER_AGENT' => self::USER_AGENT,
            'HTTP_CF_IPCOUNTRY' => 'NG',
            'HTTP_CF_REGION' => 'Lagos',
            'HTTP_CF_IPCITY' => 'Ikeja',
        ], [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }
}
