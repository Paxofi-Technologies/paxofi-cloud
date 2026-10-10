<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Migrations;

use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PHPUnit\Framework\TestCase;

final class MigrationLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pc-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->clearDir();
        rmdir($this->dir);
    }

    private function clearDir(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            // Only files this test wrote into its own random temp directory.
            unlink($file); // nosemgrep
        }
    }

    public function testLoadsInNumericOrderAndIgnoresOtherFiles(): void
    {
        file_put_contents($this->dir . '/0010_add_index.sql', 'CREATE INDEX i ON t (a);');
        file_put_contents($this->dir . '/0002_create_table.sql', 'CREATE TABLE t (a INT);');
        file_put_contents($this->dir . '/README.md', 'notes');

        $migrations = (new MigrationLoader())->load($this->dir);

        self::assertSame(['0002', '0010'], array_map(static fn ($m): string => $m->version, $migrations));
        self::assertSame('create_table', $migrations[0]->name);
        self::assertSame(hash('sha256', 'CREATE TABLE t (a INT);'), $migrations[0]->checksum);
    }

    public function testRejectsBadNamesDuplicatesAndEmptyFiles(): void
    {
        $cases = [
            ['1_too_short.sql' => 'SELECT 1;'],
            ['0001_Bad-Name.sql' => 'SELECT 1;'],
            ['0001_a.sql' => 'SELECT 1;', '00001_b.sql' => 'SELECT 2;'],
            ['0001_empty.sql' => "  \n"],
        ];
        foreach ($cases as $files) {
            $this->clearDir();
            foreach ($files as $name => $sql) {
                file_put_contents($this->dir . '/' . $name, $sql);
            }
            try {
                (new MigrationLoader())->load($this->dir);
                self::fail('Expected MigrationError for ' . implode(', ', array_keys($files)));
            } catch (MigrationError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
