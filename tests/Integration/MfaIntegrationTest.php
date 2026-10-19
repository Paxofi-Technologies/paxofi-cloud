<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\MfaCookie;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

/**
 * Two-step verification through the real HTTP stack, MySQL 8.4 and Redis 7
 * (SRS IAM-007, IAM-013, IAM-014; threat model D-4, D-5, T-13..T-17, §6.13).
 */
final class MfaIntegrationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';

    private OutboxSealer $opener;
    private RedisClient $redis;
    private string $origin;
    private string $ip;
    private string $sid = '';
    private string $csrf = '';

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
        $this->ip = '198.' . random_int(18, 19) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        putenv('EMAIL_OUTBOX_PUBLIC_KEY');
        parent::tearDown();
    }

    public function testEnrolSignInWithACodeAndARecoveryCodeThenDisable(): void
    {
        $email = $this->activeCustomer();
        $this->signInWithPassword($email);

        self::assertSame(['totp_enabled' => false, 'recovery_codes_left' => 0], self::json($this->get('/api/v1/account/mfa')));

        // T-17: no setup without the current password.
        self::assertSame(422, $this->write('/api/v1/account/mfa/totp/setup', ['password' => 'not the password'])->status());
        $setup = self::json($this->write('/api/v1/account/mfa/totp/setup', ['password' => self::PASSWORD]));
        self::assertIsString($setup['secret']);
        self::assertIsString($setup['otpauth_uri']);
        self::assertStringStartsWith('otpauth://totp/PaxofiCloud:', $setup['otpauth_uri']);
        $secret = self::fromBase32($setup['secret']);

        // T-15: the database holds only the bound ciphertext.
        $row = self::query('SELECT secret_envelope, confirmed_at FROM identity_totp')->fetch();
        self::assertIsArray($row);
        self::assertIsString($row['secret_envelope']);
        self::assertStringStartsWith('v1.t1.', $row['secret_envelope']);
        self::assertStringNotContainsString($setup['secret'], $row['secret_envelope']);
        self::assertNull($row['confirmed_at']);

        $confirmed = self::json($this->write('/api/v1/account/mfa/totp/confirm', ['code' => self::code($secret)]));
        self::assertSame('enabled', $confirmed['status']);
        self::assertIsArray($confirmed['recovery_codes']);
        self::assertCount(10, $confirmed['recovery_codes']);
        $recovery = $confirmed['recovery_codes'];
        self::assertIsString($recovery[0]);
        $hashes = json_encode(self::query('SELECT code_hash FROM identity_recovery_codes')->fetchAll(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(str_replace('-', '', $recovery[0]), $hashes, 'D-5');

        // Sign out, then the password alone is no longer enough.
        $this->write('/api/v1/auth/sign-out', []);
        $first = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(200, $first->status());
        self::assertSame('{"status":"mfa_required"}', $first->body());
        $cookies = $first->headers()['set-cookie'] ?? [];
        self::assertCount(3, $cookies);
        self::assertStringStartsWith('__Host-pc_dev=', $cookies[2], 'the device cookie arrives with the password step (§6.15)');
        self::assertStringStartsWith(SessionCookie::NAME . '=; ', $cookies[0], 'No session cookie yet');
        self::assertMatchesRegularExpression('/^__Host-pc_mfa=[A-Za-z0-9_-]{43}; Path=\/; Max-Age=300; Secure; HttpOnly; SameSite=Strict$/', $cookies[1]);
        $ticket = substr(explode(';', $cookies[1])[0], strlen(MfaCookie::NAME) + 1);
        self::assertTrue($this->redis->exists('pc:mfa:t:' . hash('sha256', $ticket)), 'D-2: ticket stored by hash');
        self::assertFalse($this->redis->exists('pc:mfa:t:' . $ticket));

        // A wrong code keeps the ticket; the right code gives a session and ends it.
        self::assertSame(422, $this->mfaStep($ticket, ['code' => '000000'])->status());
        $done = $this->mfaStep($ticket, ['code' => self::code($secret, 1)]);
        self::assertSame(200, $done->status());
        $doneCookies = $done->headers()['set-cookie'] ?? [];
        self::assertStringStartsWith(MfaCookie::NAME . '=; ', $doneCookies[0]);
        $this->sid = substr(explode(';', $doneCookies[1])[0], strlen(SessionCookie::NAME) + 1);
        $csrf = self::json($done)['csrf_token'] ?? null;
        self::assertIsString($csrf);
        $this->csrf = $csrf;
        self::assertSame(200, $this->get('/api/v1/auth/session')->status());
        self::assertSame(401, $this->mfaStep($ticket, ['code' => self::code($secret, 1)])->status(), 'Ticket is single use');

        // T-13: the same code cannot sign in twice.
        $again = $this->passwordTicket($email);
        self::assertSame(422, $this->mfaStep($again, ['code' => self::code($secret, 1)])->status());

        // A recovery code works once.
        self::assertSame(200, $this->mfaStep($again, ['recovery_code' => strtolower($recovery[0])])->status());
        $third = $this->passwordTicket($email);
        self::assertSame(422, $this->mfaStep($third, ['recovery_code' => $recovery[0]])->status());
        self::assertSame(9, self::json($this->get('/api/v1/account/mfa'))['recovery_codes_left'] ?? null);

        // Disable needs password and a second factor; afterwards the password is enough again.
        self::assertIsString($recovery[1]);
        self::assertSame(422, $this->write('/api/v1/account/mfa/disable', ['password' => self::PASSWORD, 'recovery_code' => 'AAAA-AAAA-AAAA-AAAA'])->status());
        self::assertSame(200, $this->write('/api/v1/account/mfa/disable', ['password' => self::PASSWORD, 'recovery_code' => $recovery[1]])->status());
        self::assertSame(0, (int) self::query('SELECT COUNT(*) FROM identity_recovery_codes')->fetchColumn());
        $plain = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertStringContainsString('"status":"signed_in"', $plain->body());
    }

    public function testFiveWrongCodesLockTheSecondStep(): void
    {
        $email = $this->activeCustomer();
        $this->signInWithPassword($email);
        $secret = $this->enrol();

        $ticket = $this->passwordTicket($email);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->mfaStep($ticket, ['code' => '00000' . $i])->status());
        }
        self::assertSame(401, $this->mfaStep($ticket, ['code' => self::code($secret, 1)])->status(), 'T-14: ticket ended');

        $fresh = $this->passwordTicket($email);
        $locked = $this->mfaStep($fresh, ['code' => self::code($secret, 1)]);
        self::assertSame(429, $locked->status());
        self::assertSame('900', $locked->header('retry-after'));
    }

    public function testMfaRoutesNeedASessionAndItsCsrfToken(): void
    {
        $email = $this->activeCustomer();
        self::assertSame(401, $this->get('/api/v1/account/mfa')->status());
        $this->signInWithPassword($email);

        $csrf = $this->csrf;
        $this->csrf = 'not-the-token';
        self::assertSame(403, $this->write('/api/v1/account/mfa/totp/setup', ['password' => self::PASSWORD])->status());
        $this->csrf = $csrf;
        self::assertSame(0, (int) self::query('SELECT COUNT(*) FROM identity_totp')->fetchColumn());
        self::assertSame(403, $this->send('POST', '/api/v1/account/mfa/totp/setup', '{"password":"x"}', ['HTTP_ORIGIN' => 'https://evil.example'])->status());
    }

    public function testTheDatabaseRefusesAPlaintextSecret(): void
    {
        $this->activeCustomer();
        $id = self::query('SELECT id FROM identities')->fetchColumn();
        self::assertIsString($id);

        $this->expectException(\PDOException::class);
        self::pdo()->prepare('INSERT INTO identity_totp (identity_id, secret_envelope) VALUES (:id, :s)')->execute(['id' => $id, 's' => 'GEZDGNBVGY3TQOJQ']);
    }

    private static function query(string $sql): \PDOStatement
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement;
    }

    /** §6.21: turning two-step verification on ends every other session of the customer. */
    public function testEnablingMfaSignsOutOtherSessions(): void
    {
        $email = $this->activeCustomer();
        $this->signInWithPassword($email);
        $other = $this->sid;
        $this->signInWithPassword($email);
        $sessionOf = fn (string $sid): int => $this->send('GET', '/api/v1/auth/session', '', ['HTTP_COOKIE' => SessionCookie::NAME . '=' . $sid])->status();
        self::assertSame(200, $sessionOf($other));

        $this->enrol();

        self::assertSame(401, $sessionOf($other), 'the other browser is signed out');
        self::assertSame(200, $sessionOf($this->sid), 'the browser that made the change stays signed in');
    }

    private function enrol(): string
    {
        $setup = self::json($this->write('/api/v1/account/mfa/totp/setup', ['password' => self::PASSWORD]));
        self::assertIsString($setup['secret']);
        $secret = self::fromBase32($setup['secret']);
        self::assertSame(200, $this->write('/api/v1/account/mfa/totp/confirm', ['code' => self::code($secret)])->status());

        return $secret;
    }

    private function passwordTicket(string $email): string
    {
        $response = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame('{"status":"mfa_required"}', $response->body());
        $cookie = ($response->headers()['set-cookie'] ?? [])[1] ?? '';

        return substr(explode(';', $cookie)[0], strlen(MfaCookie::NAME) + 1);
    }

    /** @param array<string, string> $data */
    private function mfaStep(string $ticket, array $data): HttpResponse
    {
        return $this->send('POST', '/api/v1/auth/sign-in/mfa', json_encode($data, JSON_THROW_ON_ERROR), ['HTTP_COOKIE' => MfaCookie::NAME . '=' . $ticket]);
    }

    private function signInWithPassword(string $email): void
    {
        $response = $this->post('/api/v1/auth/sign-in', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(200, $response->status());
        $this->sid = substr(explode(';', (string) $response->header('set-cookie'))[0], strlen(SessionCookie::NAME) + 1);
        $csrf = self::json($response)['csrf_token'] ?? null;
        self::assertIsString($csrf);
        $this->csrf = $csrf;
    }

    private static function code(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::step(new \DateTimeImmutable()) + $offset);
    }

    private static function fromBase32(string $base32): string
    {
        $bits = '';
        foreach (str_split($base32) as $char) {
            $bits .= str_pad(decbin((int) strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(static fn (string $b): string => chr((int) bindec($b)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }

    private function activeCustomer(): string
    {
        $email = 'ada.' . bin2hex(random_bytes(4)) . '@example.ng';
        $form = ['full_name' => 'Ada Okafor', 'email' => $email, 'phone' => '+2348031234567', 'password' => self::PASSWORD, 'accept_terms' => true];
        self::assertSame(202, $this->post('/api/v1/auth/register', $form)->status());
        $sealed = self::query("SELECT payload FROM email_outbox WHERE template = 'verify-email' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        self::assertIsString($sealed);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['parameters'] ?? null);
        self::assertSame(200, $this->post('/api/v1/auth/verify-email', ['token' => $payload['parameters']['token'] ?? ''])->status());

        return $email;
    }

    /** @return array<array-key, mixed> */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function post(string $path, array $data): HttpResponse
    {
        return $this->send('POST', $path, json_encode((object) $data, JSON_THROW_ON_ERROR), []);
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): HttpResponse
    {
        return $this->send('POST', $path, json_encode((object) $data, JSON_THROW_ON_ERROR), [
            'HTTP_COOKIE' => SessionCookie::NAME . '=' . $this->sid,
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ]);
    }

    private function get(string $path): HttpResponse
    {
        return $this->send('GET', $path, '', $this->sid === '' ? [] : ['HTTP_COOKIE' => SessionCookie::NAME . '=' . $this->sid]);
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
            'HTTP_ORIGIN' => $this->origin,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ], [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }
}
