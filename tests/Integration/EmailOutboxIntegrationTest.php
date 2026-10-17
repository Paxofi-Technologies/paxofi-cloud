<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\MysqlEmailOutbox;
use PaxofiCloud\Infrastructure\Email\MysqlOutboxReport;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Tests\Support\MutableClock;

/** Threat model THREAT-MODEL-TRANSACTIONAL-EMAIL M-01, M-11 and E-3 on real MySQL 8.4. */
final class EmailOutboxIntegrationTest extends IntegrationTestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz012345ABCDEFGH';

    private MutableClock $clock;
    private MysqlEmailOutbox $web;
    private MysqlEmailOutbox $worker;
    private OutboxSealer $opener;
    private MysqlOutboxReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $app = AppFactory::create(self::basePath());
        $connection = Resolve::get($app->container(), Connection::class);
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));

        $pair = OutboxSealer::generateKeyPair();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->opener = OutboxSealer::forOpening($pair['public'], $pair['secret']);
        $this->web = new MysqlEmailOutbox($connection, $repository, OutboxSealer::forSealing($pair['public']), $this->clock);
        $this->worker = new MysqlEmailOutbox($connection, $repository, $this->opener, $this->clock);
        $this->report = new MysqlOutboxReport($repository);
    }

    public function testReportShowsCountsAndFailureReasonsButNoRecipients(): void
    {
        $sent = $this->web->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $dead = $this->web->enqueue(new EmailAddress('bola@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $retry = $this->web->enqueue(new EmailAddress('chi@example.ng'), 'account-exists', []);
        $this->worker->markSent($sent, 'smtp', 'm-1', $this->clock->now());
        $this->worker->markDead($dead, 0, 'suppressed-not-allowlisted');
        $this->worker->reschedule($retry, 1, $this->clock->now()->modify('+1 minute'), 'smtp: connect failed');

        self::assertSame(['pending' => 1, 'sending' => 0, 'sent' => 1, 'dead' => 1], $this->report->countsByStatus());
        $problems = $this->report->recentProblems(5);
        self::assertCount(2, $problems);
        self::assertEqualsCanonicalizing(['suppressed-not-allowlisted', 'smtp: connect failed'], array_column($problems, 'last_error'));
        $dump = json_encode($problems, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@example.ng', $dump);
        self::assertStringNotContainsString((new EmailAddress('bola@example.ng'))->hash(), $dump);
    }

    public function testStoredRowsContainNoAddressOrToken(): void
    {
        $this->web->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);

        $dump = json_encode(self::rows('SELECT * FROM email_outbox'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('ada@example.ng', $dump);
        self::assertStringContainsString((new EmailAddress('ada@example.ng'))->hash(), $dump);
    }

    public function testClaimIsExclusiveAndFinalStatesPurgeThePayload(): void
    {
        $id = $this->web->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);

        $first = $this->worker->claim(10, $this->clock->now(), 300);
        self::assertCount(1, $first);
        self::assertStringContainsString(self::TOKEN, $this->opener->open($first[0]->payload));
        self::assertSame([], $this->worker->claim(10, $this->clock->now(), 300), 'A claimed row must not be claimed twice (M-11)');

        // A crashed worker's lock expires and the row becomes claimable again.
        $this->clock->advance('+301 seconds');
        self::assertCount(1, $this->worker->claim(10, $this->clock->now(), 300));

        $this->worker->markSent($id, 'postmark', 'pm-1', $this->clock->now());
        $row = self::rows("SELECT status, payload, provider, attempts FROM email_outbox WHERE id = '{$id}'")[0] ?? null;
        self::assertSame(['status' => 'sent', 'payload' => null, 'provider' => 'postmark', 'attempts' => 1], $row);
        self::assertSame(1, $this->worker->sentSince($this->clock->now()->modify('-1 hour')));
    }

    public function testDatabaseRefusesAFinalRowThatStillHoldsAPayload(): void
    {
        $id = $this->web->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);

        $this->expectException(\PDOException::class);
        self::pdo()->exec("UPDATE email_outbox SET status = 'sent' WHERE id = '{$id}'");
    }

    public function testRescheduledRowsWaitUntilDue(): void
    {
        $id = $this->web->enqueue(new EmailAddress('ada@example.ng'), 'account-exists', []);
        $this->worker->claim(10, $this->clock->now(), 300);
        $this->worker->reschedule($id, 1, $this->clock->now()->modify('+2 minutes'), 'Postmark unreachable');

        self::assertSame([], $this->worker->claim(10, $this->clock->now(), 300));
        $this->clock->advance('+3 minutes');
        $items = $this->worker->claim(10, $this->clock->now(), 300);
        self::assertSame(1, $items[0]->attempts ?? null);

        $this->worker->markDead($id, 2, 'rejected');
        self::assertSame([['status' => 'dead', 'payload' => null]], self::rows("SELECT status, payload FROM email_outbox WHERE id = '{$id}'"));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $sql): array
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }
}
