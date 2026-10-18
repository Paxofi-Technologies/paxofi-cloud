<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffEnrolment;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
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
 * Staff passkeys on MySQL 8.4 and Redis 7 (migration 0008; SRS IAM-008;
 * threat model D-10, D-11, T-29, T-30, §6.17): operator bootstrap, two-passkey
 * enrolment, usernameless sign-in, single-use Redis ceremonies, clone
 * detection, and the database CHECKs.
 */
final class StaffPasskeysIntegrationTest extends IntegrationTestCase
{
    private const string ORIGIN = 'https://admin.example.test';

    private OutboxSealer $opener;
    private PDO $pdo;
    private StaffInvitations $invitations;
    private StaffEnrolment $enrolment;
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
        $this->enrolment = Resolve::get($app->container(), StaffEnrolment::class);
        $this->signIn = Resolve::get($app->container(), StaffPasskeySignIn::class);
        $this->authorizer = Resolve::get($app->container(), StaffAuthorizer::class);
        $this->pdo = self::pdo();
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
        $command = new StaffBootstrapCommand(fn (): StaffInvitations => $this->invitations, $streams->console());
        self::assertSame(0, $command->execute(['ada@example.test', 'Ada Lovelace']));
        $token = $this->invitationToken();
        self::assertStringNotContainsString($token, $streams->output(), 'the link is never printed');
        self::assertSame(2, $command->execute(['ada@example.test', 'Ada Lovelace']), 'an open invitation for the same address is refused');

        $staffId = $this->scalar('SELECT id FROM staff_members', []);
        self::assertSame('invited', $this->scalar('SELECT status FROM staff_members WHERE id = ?', [$staffId]));
        self::assertSame(hash('sha256', $token), $this->scalar('SELECT token_hash FROM staff_invitations WHERE staff_id = ?', [$staffId]));

        $key1 = new SoftwareAuthenticator(self::ORIGIN);
        $key2 = new SoftwareAuthenticator(self::ORIGIN, -8);
        self::assertSame(['passkeys' => 1, 'active' => false], $this->enrol($token, $key1));
        self::assertSame(['passkeys' => 2, 'active' => true], $this->enrol($token, $key2));
        self::assertSame('active', $this->scalar('SELECT status FROM staff_members WHERE id = ?', [$staffId]));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_invitations WHERE staff_id = ? AND used_at IS NOT NULL', [$staffId]));
        self::assertSame('-7,-8', $this->scalar('SELECT GROUP_CONCAT(algorithm ORDER BY algorithm DESC) FROM staff_passkeys WHERE staff_id = ?', [$staffId]));
        self::assertSame(hash('sha256', $key1->credentialId), $this->scalar('SELECT credential_lookup FROM staff_passkeys WHERE algorithm = -7', []));

        // Bootstrap is now refused: an active staff manager exists.
        self::assertSame(2, $command->execute(['bob@example.test', 'Bob']));

        $handle = $this->scalar('SELECT webauthn_user_handle FROM staff_members WHERE id = ?', [$staffId]);
        $signedIn = [$this->signInWith($key1, $handle), $this->signInWith($key1, $handle)];
        self::assertSame([$staffId, $staffId], $signedIn);
        self::assertSame('2', $this->scalar('SELECT sign_count FROM staff_passkeys WHERE algorithm = -7', []));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM staff_passkeys WHERE last_used_at IS NOT NULL', []));
        $this->authorizer->authorize($staffId, Permission::StaffManage, $this->client);

        $types = $this->pdo->query('SELECT event_type FROM audit_events ORDER BY seq');
        self::assertInstanceOf(\PDOStatement::class, $types);
        self::assertSame([
            AuditEventType::STAFF_BOOTSTRAP_INVITED,
            AuditEventType::STAFF_INVITATION_REFUSED,
            AuditEventType::STAFF_PASSKEY_REGISTERED,
            AuditEventType::STAFF_PASSKEY_REGISTERED,
            AuditEventType::STAFF_ACTIVATED,
            AuditEventType::STAFF_INVITATION_REFUSED,
            AuditEventType::STAFF_SIGNED_IN,
            AuditEventType::STAFF_SIGNED_IN,
        ], $types->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRedisCeremoniesAreSingleUseAndClonesAreDisabled(): void
    {
        $this->invitations->bootstrap('ada@example.test', 'Ada', $this->client);
        $token = $this->invitationToken();
        $key1 = new SoftwareAuthenticator(self::ORIGIN);
        $this->enrol($token, $key1);
        $this->enrol($token, new SoftwareAuthenticator(self::ORIGIN));
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

    /** @return array{passkeys: int, active: bool} */
    private function enrol(string $token, SoftwareAuthenticator $authenticator): array
    {
        $begun = $this->enrolment->begin($token, $this->client);

        return $this->enrolment->finish($token, $begun['ceremony_id'], $authenticator->register($begun['options']), '', $this->client);
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
