<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditLogQuery;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditChain;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditLog;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

/** The staff audit log search on MySQL 8.4 (identity threat model §6.24). */
final class AuditSearchIntegrationTest extends IntegrationTestCase
{
    private const string CUSTOMER = 'c0000000000000000000000000000001';
    private const string ACCOUNT = 'd0000000000000000000000000000001';
    private const string STAFF = 'a0000000000000000000000000000001';

    private MysqlAuditLog $log;
    private Repository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $this->repository = Resolve::get($container, Repository::class);
        $this->connection = Resolve::get($container, Connection::class);
        (new Migrator($this->connection, $this->repository, new MysqlAdvisoryLock($this->repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->log = new MysqlAuditLog($this->connection, $this->repository, Resolve::get($container, TransactionManager::class));

        $client = ClientContext::from('203.0.113.45', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0', 'NG', 'Lagos', 'Ikeja', 'req-1');
        $at = new \DateTimeImmutable('2026-10-24T10:00:00Z');
        $this->log->append(new AuditEvent('auth.sign_in.succeeded', AuditEvent::SUCCESS, $at, $client, self::CUSTOMER, self::ACCOUNT, []));
        $this->log->append(new AuditEvent('auth.sign_in.failed', AuditEvent::FAILURE, $at, $client, self::CUSTOMER, null, ['reason' => 'wrong_password']));
        $this->log->append(new AuditEvent('staff.signed_in', AuditEvent::SUCCESS, $at, $client, null, null, ['subject' => self::STAFF, 'method' => 'password_codes']));
        $this->log->append(new AuditEvent('provider_prices.synced', AuditEvent::SUCCESS, $at, ClientContext::from('', '', null, null, null, 'req-2'), null, null, ['items' => 631]));
    }

    public function testSearchesNewestFirstWithContextAndFilters(): void
    {
        $all = $this->log->search(AuditLogQuery::from([]), 10);
        self::assertSame([4, 3, 2, 1], array_map(static fn ($r): int => $r->seq, $all));
        self::assertNull($all[0]->ip, 'an event without request context');
        self::assertSame(['203.0.113.45', '203.0.113.0/24', 'NG', 'Ikeja'], [$all[1]->ip, $all[1]->ipNetwork, $all[1]->country, $all[1]->city]);
        self::assertEquals(['subject' => self::STAFF, 'method' => 'password_codes'], $all[1]->details, 'canonical JSON sorts keys');

        $seqs = fn (array $input): array => array_map(static fn ($r): int => $r->seq, $this->log->search(AuditLogQuery::from($input), 10));
        self::assertSame([2], $seqs(['outcome' => 'failure']));
        self::assertSame([2, 1], $seqs(['group' => 'auth']));
        self::assertSame([3], $seqs(['subject' => self::STAFF]), 'staff subject in details');
        self::assertSame([2, 1], $seqs(['subject' => self::CUSTOMER]));
        self::assertSame([1], $seqs(['subject' => self::ACCOUNT]), 'account match');
        self::assertSame([2, 1], $seqs(['before' => '3']));
        self::assertSame([4], array_map(static fn ($r): int => $r->seq, $this->log->search(AuditLogQuery::from([]), 1)), 'limit');
    }

    public function testTheHeadMatchesTheChain(): void
    {
        $head = $this->log->head();

        self::assertSame(4, $head['seq']);
        self::assertSame((new MysqlAuditChain($this->connection, $this->repository))->verify()->headHash, $head['hash']);
    }

    public function testPurgedContextShowsAsNotKept(): void
    {
        $this->connection->execute('DELETE FROM audit_event_context WHERE event_seq = 2');

        $record = $this->log->search(AuditLogQuery::from(['before' => '3']), 1)[0];
        self::assertSame(2, $record->seq);
        self::assertNull($record->ip);
        self::assertNull($record->ipNetwork);
        self::assertNull($record->userAgent);
        self::assertNull($record->device);
    }
}
