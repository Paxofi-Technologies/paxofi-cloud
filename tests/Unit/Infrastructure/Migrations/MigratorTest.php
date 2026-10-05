<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Migrations;

use PaxofiCloud\Infrastructure\Migrations\Migration;
use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\MigrationState;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Tests\Support\InMemoryMigrationDatabase;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private InMemoryMigrationDatabase $db;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->db = new InMemoryMigrationDatabase();
        $this->migrator = new Migrator($this->db, $this->db, $this->db);
    }

    public function testAppliesPendingMigrationsInOrderOnce(): void
    {
        $migrations = [
            new Migration('0001', 'create_a', 'CREATE TABLE a (id INT);'),
            new Migration('0002', 'create_b', "CREATE TABLE b (id INT);\nCREATE INDEX ib ON b (id);"),
        ];

        $reported = [];
        $applied = $this->migrator->migrate($migrations, function (Migration $m, int $ms) use (&$reported): void {
            $reported[] = $m->version;
        });

        self::assertSame(['0001', '0002'], array_map(static fn (Migration $m): string => $m->version, $applied));
        self::assertSame(['0001', '0002'], $reported);
        self::assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)', 'CREATE INDEX ib ON b (id)'], $this->db->executed);
        self::assertFalse($this->db->locked, 'lock must be released');

        self::assertSame([], $this->migrator->migrate($migrations), 'second run applies nothing');
        self::assertCount(3, $this->db->executed);
    }

    public function testRefusesWhenAnAppliedMigrationWasModified(): void
    {
        $this->migrator->migrate([new Migration('0001', 'create_a', 'CREATE TABLE a (id INT);')]);

        $this->expectExceptionObject(new MigrationError('Migration 0001 (create_a) was modified after it was applied. Write a new migration instead.'));
        $this->migrator->migrate([
            new Migration('0001', 'create_a', 'CREATE TABLE a (id BIGINT);'),
            new Migration('0002', 'create_b', 'CREATE TABLE b (id INT);'),
        ]);
    }

    public function testNothingIsAppliedWhenHistoryIsInconsistent(): void
    {
        $this->migrator->migrate([new Migration('0001', 'create_a', 'CREATE TABLE a (id INT);')]);
        $before = $this->db->executed;

        try {
            $this->migrator->migrate([
                new Migration('0001', 'create_a', 'CHANGED;'),
                new Migration('0002', 'create_b', 'CREATE TABLE b (id INT);'),
            ]);
            self::fail('Expected MigrationError');
        } catch (MigrationError) {
            self::assertSame($before, $this->db->executed);
            self::assertFalse($this->db->locked);
        }
    }

    public function testRefusesWhenAnAppliedMigrationIsMissing(): void
    {
        $this->migrator->migrate([new Migration('0001', 'create_a', 'CREATE TABLE a (id INT);')]);

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('missing from disk');
        $this->migrator->migrate([new Migration('0002', 'create_b', 'CREATE TABLE b (id INT);')]);
    }

    public function testRefusesOutOfOrderMigrations(): void
    {
        $this->migrator->migrate([new Migration('0005', 'create_a', 'CREATE TABLE a (id INT);')]);

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('older than the newest applied');
        $this->migrator->migrate([
            new Migration('0003', 'late', 'CREATE TABLE late (id INT);'),
            new Migration('0005', 'create_a', 'CREATE TABLE a (id INT);'),
        ]);
    }

    public function testDoesNothingWhenAnotherRunnerHoldsTheLock(): void
    {
        $this->db->lockAvailable = false;

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('holds the lock');
        try {
            $this->migrator->migrate([new Migration('0001', 'create_a', 'CREATE TABLE a (id INT);')]);
        } finally {
            self::assertSame([], $this->db->executed);
        }
    }

    public function testFailedStatementIsReportedAndNotRecorded(): void
    {
        $this->db->failOn = 'BROKEN';

        try {
            $this->migrator->migrate([new Migration('0001', 'create_a', "CREATE TABLE a (id INT);\nBROKEN SQL;")]);
            self::fail('Expected MigrationError');
        } catch (MigrationError $error) {
            self::assertStringContainsString('0001 (create_a) failed at statement 2 of 2', $error->getMessage());
            self::assertSame([], $this->db->rows);
            self::assertFalse($this->db->locked);
        }
    }

    public function testStatusReportsEveryState(): void
    {
        $this->migrator->migrate([
            new Migration('0001', 'kept', 'CREATE TABLE a (id INT);'),
            new Migration('0002', 'edited', 'CREATE TABLE b (id INT);'),
            new Migration('0003', 'deleted', 'CREATE TABLE c (id INT);'),
        ]);

        $status = $this->migrator->status([
            new Migration('0001', 'kept', 'CREATE TABLE a (id INT);'),
            new Migration('0002', 'edited', 'CREATE TABLE b (id BIGINT);'),
            new Migration('0004', 'new', 'CREATE TABLE d (id INT);'),
        ]);

        self::assertSame(
            ['0001' => MigrationState::Applied, '0002' => MigrationState::Modified, '0003' => MigrationState::Missing, '0004' => MigrationState::Pending],
            array_column($status, 'state', 'version'),
        );
    }
}
