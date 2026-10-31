<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeyRegistration;
use PaxofiCloud\Application\Staff\Setup\StaffSignInSetup;
use PaxofiCloud\Application\Staff\SignIn\StaffCodeSignIn;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\SignIn\StaffSignInRefused;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\FounderBootstrap;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Infrastructure\Time\SystemClock;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Cli\StaffBootstrapCommand;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PDO;

/**
 * Staff sign-in on MySQL 8.4 and Redis 7 (migrations 0008, 0009; SRS IAM-008;
 * threat model D-10, D-11, T-29, T-30, §6.17, §6.20): operator bootstrap,
 * password + authenticator setup, three-step code sign-in, optional passkeys
 * with step-up, usernameless passkey sign-in, single-use Redis ceremonies and
 * tickets, clone detection, and the database CHECKs.
 */
final class StaffPasskeysIntegrationTest extends IntegrationTestCase
{
    private const string ORIGIN = 'https://admin.example.test';
    private const string PASSWORD = 'correct horse battery staple';

    private int $totpOffset = 0;

    private OutboxSealer $opener;
    private PDO $pdo;
    private StaffInvitations $invitations;
    private FounderBootstrap $founder;
    private StaffSignInSetup $setup;
    private StaffCodeSignIn $codes;
    private StaffPasskeyRegistration $registration;
    private StaffPasskeySignIn $signIn;
    private StaffAuthorizer $authorizer;
    private ClientContext $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $pair = OutboxSealer::generateKeyPair();
        putenv('EMAIL_OUTBOX_PUBLIC_KEY=' . $pair['public']);
        putenv('ADMIN_URL=' . self::ORIGIN);
        $this->opener = OutboxSealer::forOpening($pair['public'], $pair['secret']);

        $app = AppFactory::create(self::basePath());
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator(Resolve::get($app->container(), Connection::class), $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->invitations = Resolve::get($app->container(), StaffInvitations::class);
        $this->founder = new FounderBootstrap(
            Resolve::get($app->container(), StaffDirectory::class),
            Resolve::get($app->container(), TransactionManager::class),
            new SystemClock(),
            Resolve::get($app->container(), AuditTrail::class),
            Resolve::get($app->container(), EmailOutbox::class),
        );
        $this->setup = Resolve::get($app->container(), StaffSignInSetup::class);
        $this->codes = Resolve::get($app->container(), StaffCodeSignIn::class);
        $this->registration = Resolve::get($app->container(), StaffPasskeyRegistration::class);
        $this->signIn = Resolve::get($app->container(), StaffPasskeySignIn::class);
        $this->authorizer = Resolve::get($app->container(), StaffAuthorizer::class);
        $this->pdo = self::pdo();
        // The per-address password limit is not per IP, so its Redis window would outlive one test.
        foreach (['ada@example.test', 'sam@example.test', 'nobody@example.test'] as $address) {
            Resolve::get($app->container(), RedisClient::class)->delete('pc:ratelimit:staff:password:address:' . hash('sha256', $address));
        }
        // TOTP: do not start in the last seconds of a 30-second step (see nextTotp).
        $left = Totp::PERIOD - time() % Totp::PERIOD;
        if ($left <= 10) {
            sleep($left);
        }
        // Fresh IP per test: Redis rate-limit windows outlive a single test.
        $this->client = ClientContext::from('198.18.' . random_int(0, 255) . '.' . random_int(1, 254), 'phpunit', 'NG', null, null, 'req-staff-it');
    }

    protected function tearDown(): void
    {
        putenv('ADMIN_URL');
        parent::tearDown();
    }

    public function testBootstrapEnrolSignInEndToEnd(): void
    {
        $streams = new ConsoleStreams();
        $command = new StaffBootstrapCommand(fn (): StaffInvitations => $this->invitations, $streams->console(), fn (): FounderBootstrap => $this->founder);
        self::assertSame(0, $command->execute(['ada@example.test', 'Ada Lovelace']));
        $lost = $this->invitationToken();
        // Running it again before the first admin has finished setting up re-issues the link (lost email) and kills the old one.
        self::assertSame(0, $command->execute(['ada@example.test', 'Ada Lovelace']));
        $token = $this->invitationToken();
        self::assertNotSame($lost, $token);
        self::assertStringNotContainsString($token, $streams->output(), 'the link is never printed');

        $staffId = $this->scalar('SELECT id FROM staff_members', []);
        self::assertSame('invited', $this->scalar('SELECT status FROM staff_members WHERE id = ?', [$staffId]));
        self::assertSame(hash('sha256', $token), $this->scalar('SELECT token_hash FROM staff_invitations WHERE staff_id = ?', [$staffId]));

        // §6.20: password and authenticator with the invitation; the member is active at once.
        $recovery = $this->activate($token, $staffId);
        self::assertCount(10, $recovery);
        self::assertSame('active', $this->scalar('SELECT status FROM staff_members WHERE id = ?', [$staffId]));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_invitations WHERE staff_id = ? AND used_at IS NOT NULL', [$staffId]));
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=1$', $this->scalar('SELECT password_hash FROM staff_members WHERE id = ?', [$staffId]));
        self::assertStringStartsWith('v1.', $this->scalar('SELECT secret_envelope FROM staff_totp WHERE staff_id = ? AND confirmed_at IS NOT NULL', [$staffId]));
        self::assertSame('10', $this->scalar('SELECT COUNT(*) FROM staff_recovery_codes WHERE staff_id = ?', [$staffId]));
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM staff_recovery_codes WHERE code_hash = ?', [strtoupper(str_replace('-', '', $recovery[0]))]));

        // Bootstrap is now refused for anyone else: an active staff manager exists (§6.23).
        self::assertSame(2, $command->execute(['bob@example.test', 'Bob']));
        // The sole founder, with their own email, gets the Catalogue role once.
        self::assertSame(0, $command->execute(['ADA@example.test', 'Ada Lovelace']));
        self::assertStringContainsString('founder roles granted: catalogue', $streams->output());
        self::assertSame(0, $command->execute(['ada@example.test', 'Ada Lovelace']));
        self::assertStringContainsString('founder already holds the founder roles', $streams->output());
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $this->client);

        // Password, authenticator code, emailed code; the ticket lives in Redis between steps.
        self::assertSame($staffId, $this->signInWithCodes($staffId));
        $this->authorizer->authorize($staffId, Permission::StaffManage, $this->client);

        // An optional passkey needs a current authenticator code, then signs in on its own.
        $key1 = new SoftwareAuthenticator(self::ORIGIN);
        $begun = $this->registration->begin($staffId, $this->nextTotp($staffId), $this->client);
        self::assertSame(['passkeys' => 1], $this->registration->finish($staffId, $begun['ceremony_id'], $key1->register($begun['options']), 'Laptop', $this->client));
        $handle = $this->scalar('SELECT webauthn_user_handle FROM staff_members WHERE id = ?', [$staffId]);
        self::assertSame([$staffId, $staffId], [$this->signInWith($key1, $handle), $this->signInWith($key1, $handle)]);
        self::assertSame('2', $this->scalar('SELECT sign_count FROM staff_passkeys WHERE staff_id = ?', [$staffId]));

        $types = $this->pdo->query('SELECT event_type FROM audit_events ORDER BY seq');
        self::assertInstanceOf(\PDOStatement::class, $types);
        self::assertSame([
            AuditEventType::STAFF_BOOTSTRAP_INVITED,
            AuditEventType::STAFF_BOOTSTRAP_INVITED,
            AuditEventType::STAFF_SIGN_IN_SET_UP,
            AuditEventType::STAFF_ACTIVATED,
            AuditEventType::STAFF_INVITATION_REFUSED,
            AuditEventType::STAFF_ROLE_CHANGE_REFUSED,
            AuditEventType::STAFF_INVITATION_REFUSED,
            AuditEventType::STAFF_ROLE_GRANTED,
            AuditEventType::STAFF_INVITATION_REFUSED,
            AuditEventType::STAFF_PASSWORD_ACCEPTED,
            AuditEventType::STAFF_CODE_ACCEPTED,
            AuditEventType::STAFF_EMAIL_CODE_SENT,
            AuditEventType::STAFF_SIGNED_IN,
            AuditEventType::STAFF_PASSKEY_REGISTERED,
            AuditEventType::STAFF_SIGNED_IN,
            AuditEventType::STAFF_SIGNED_IN,
        ], $types->fetchAll(PDO::FETCH_COLUMN));
    }

    /** §6.20: Redis tickets work once; a recovery code works once; wrong codes end the ticket. */
    public function testBootstrapAgainReissuesTheLostInvitationOnMysql(): void
    {
        $staffId = $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $first = $this->invitationToken();

        self::assertSame($staffId, $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client));
        $second = $this->invitationToken();

        self::assertNotSame($first, $second);
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_members', []));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_invitations WHERE used_at IS NULL', []), 'only the newest link is open');
        self::assertSame(hash('sha256', $second), $this->scalar('SELECT token_hash FROM staff_invitations WHERE staff_id = ?', [$staffId]));
        $this->activate($second, $staffId);
    }

    public function testRedisTicketsAndRecoveryCodesWorkOnce(): void
    {
        $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $staffId = $this->scalar('SELECT id FROM staff_members', []);
        $recovery = $this->activate($this->invitationToken(), $staffId);

        $first = $this->codes->password('ada@example.test', self::PASSWORD, $this->client);
        $next = $this->codes->authenticatorCode($first->ticket, $recovery[0], $this->client);
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->codes->authenticatorCode($first->ticket, $recovery[1], $this->client));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_recovery_codes WHERE staff_id = ? AND used_at IS NOT NULL', [$staffId]));

        $ticket = $next->ticket;
        for ($i = 1; $i < StaffCodeSignIn::MAX_WRONG_CODES_PER_TICKET; $i++) {
            $ticket = (string) $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->codes->emailCode($ticket, $this->wrongEmailCode(), $this->client))->ticket;
        }
        // The fifth wrong code ends the ticket; after that not even the right code works.
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->codes->emailCode($ticket, $this->wrongEmailCode(), $this->client));
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->codes->emailCode($ticket, $this->lastEmailCode(), $this->client));

        $again = $this->codes->password('ada@example.test', self::PASSWORD, $this->client);
        $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->codes->authenticatorCode($again->ticket, $recovery[0], $this->client));
    }

    public function testDatabaseRefusesANonArgon2idStaffPassword(): void
    {
        $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $staffId = $this->scalar('SELECT id FROM staff_members', []);

        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare('UPDATE staff_members SET password_hash = ? WHERE id = ?')->execute(['$2y$10$abcdefghijklmnopqrstuv', $staffId]));
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare('INSERT INTO staff_totp (staff_id, secret_envelope) VALUES (?, ?)')->execute([$staffId, 'plain-secret']));
    }

    public function testRedisCeremoniesAreSingleUseAndClonesAreDisabled(): void
    {
        $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $staffId = $this->scalar('SELECT id FROM staff_members', []);
        $this->activate($this->invitationToken(), $staffId);
        $key1 = new SoftwareAuthenticator(self::ORIGIN);
        $begun = $this->registration->begin($staffId, $this->nextTotp($staffId), $this->client);
        $this->registration->finish($staffId, $begun['ceremony_id'], $key1->register($begun['options']), '', $this->client);
        $handle = $this->scalar('SELECT webauthn_user_handle FROM staff_members', []);

        $begun = $this->signIn->begin($this->client);
        $json = $key1->assert($begun['options'], $handle);
        $this->signIn->finish($begun['ceremony_id'], $json, $this->client);
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->signIn->finish($begun['ceremony_id'], $json, $this->client));

        $key1->signCount = 0;
        $this->expectRejected(PasskeyRejected::COUNTER, fn () => $this->signInWith($key1, $handle));
        self::assertSame('counter_regression', $this->scalar('SELECT disabled_reason FROM staff_passkeys WHERE credential_lookup = ?', [hash('sha256', $key1->credentialId)]));
        $key1->signCount = 50;
        $this->expectRejected(PasskeyRejected::UNKNOWN_CREDENTIAL, fn () => $this->signInWith($key1, $handle));
    }

    public function testDatabaseRefusesInvalidPasskeyAndInvitationRows(): void
    {
        $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $staffId = $this->scalar('SELECT id FROM staff_members', []);
        $insert = 'INSERT INTO staff_passkeys (id, staff_id, credential_id, credential_lookup, public_key, algorithm, sign_count, aaguid, transports,
                       backup_eligible, backup_state, label, created_at, disabled_at, disabled_reason)
                   VALUES (?, ?, ?, ?, ?, ?, 0, ?, \'\', 0, 0, \'k\', NOW(6), ?, ?)';
        $row = static fn (string $id, int $alg, ?string $disabledAt = null, ?string $reason = null, string $lookup = ''): array => [
            $id, $staffId, 'cred-' . $id, $lookup !== '' ? $lookup : hash('sha256', 'cred-' . $id), 'cose', $alg, '00000000-0000-0000-0000-000000000000', $disabledAt, $reason,
        ];

        // RS256 (-257) is not allowed (D-10).
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($insert)->execute($row('10000000000000000000000000000000', -257)));
        // A disabled passkey must say why, and only a disabled one may.
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($insert)->execute($row('11000000000000000000000000000000', -7, '2026-10-26 09:00:00', null)));
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($insert)->execute($row('12000000000000000000000000000000', -7, null, 'counter_regression')));
        // One row per credential.
        $this->pdo->prepare($insert)->execute($row('13000000000000000000000000000000', -7, null, null, hash('sha256', 'same')));
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($insert)->execute($row('14000000000000000000000000000000', -8, null, null, hash('sha256', 'same'))));

        // An invitation must expire after it is created, and nobody invites themselves.
        $invite = 'INSERT INTO staff_invitations (token_hash, staff_id, invited_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?)';
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($invite)->execute([str_repeat('a', 64), $staffId, null, '2026-10-26 09:00:00', '2026-10-26 09:00:00']));
        $this->assertRefusedByDatabase(fn () => $this->pdo->prepare($invite)->execute([str_repeat('b', 64), $staffId, $staffId, '2026-10-26 09:00:00', '2026-10-27 09:00:00']));
    }

    /** @return list<string> */
    private function activate(string $token, string $staffId): array
    {
        $this->setup->startWithInvitation($token, $this->client);

        $this->totpOffset = -1;

        return $this->setup->finishWithInvitation($token, self::PASSWORD, $this->totpAt($staffId, -1), $this->client);
    }

    private function signInWithCodes(string $staffId): string
    {
        $step = $this->codes->password('ada@example.test', self::PASSWORD, $this->client);
        $step = $this->codes->authenticatorCode($step->ticket, $this->nextTotp($staffId), $this->client);

        return $this->codes->emailCode($step->ticket, $this->lastEmailCode(), $this->client);
    }

    /**
     * Each code works once per 30-second step, so a test walks through the
     * three steps the server accepts (previous, current, next); setUp avoids
     * starting just before a step boundary.
     */
    private function nextTotp(string $staffId): string
    {
        return $this->totpAt($staffId, ++$this->totpOffset);
    }

    private function totpAt(string $staffId, int $offset): string
    {
        $crypto = new MfaCrypto(
            new SecretCipher(KeyRing::fromNamedProcessEnvironment('MFA_SECRET_KEYS', 'MFA_SECRET_ACTIVE_KEY') ?? throw new \LogicException('MFA keys')),
            new RecoveryCodes(str_repeat("\x22", 32)),
        );
        $secret = $crypto->secrets->decrypt($this->scalar('SELECT secret_envelope FROM staff_totp WHERE staff_id = ?', [$staffId]), StaffSecondFactor::associatedData($staffId));

        return Totp::code($secret, Totp::step(new \DateTimeImmutable()) + $offset);
    }

    private function lastEmailCode(): string
    {
        $sealed = $this->scalar("SELECT payload FROM email_outbox WHERE template = 'staff-sign-in-code' ORDER BY created_at DESC LIMIT 1", []);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $parameters = $payload['parameters'] ?? null;
        self::assertIsArray($parameters);
        $code = $parameters['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    private function wrongEmailCode(): string
    {
        return $this->lastEmailCode() === '000000' ? '000001' : '000000';
    }

    private function signInWith(SoftwareAuthenticator $authenticator, string $handle): string
    {
        $begun = $this->signIn->begin($this->client);

        return $this->signIn->finish($begun['ceremony_id'], $authenticator->assert($begun['options'], $handle), $this->client);
    }

    private function invitationToken(): string
    {
        $sealed = $this->scalar("SELECT payload FROM email_outbox WHERE template = 'staff-invitation' ORDER BY created_at DESC LIMIT 1", []);
        $payload = json_decode($this->opener->open($sealed), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $parameters = $payload['parameters'] ?? null;
        self::assertIsArray($parameters);
        $token = $parameters['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();
        self::assertTrue(is_string($value) || is_int($value), 'no scalar for: ' . $sql);

        return (string) $value;
    }

    /** @param callable(): mixed $action */
    private function expectRejected(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected rejection: ' . $reason);
        } catch (PasskeyRejected $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /** @param callable(): mixed $action */
    private function expectRefused(string $reason, callable $action): StaffSignInRefused
    {
        try {
            $action();
        } catch (StaffSignInRefused $e) {
            self::assertSame($reason, $e->reason);

            return $e;
        }
        self::fail('Expected refusal: ' . $reason);
    }

    /** @param callable(): mixed $write */
    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            $write();
            self::fail('The database must refuse this row.');
        } catch (\PDOException $e) {
            $code = $e->errorInfo[1] ?? null;
            // 3819 CHECK constraint violated; 1062 duplicate key.
            self::assertContains(is_int($code) ? $code : 0, [3819, 1062], $e->getMessage());
        }
    }
}
