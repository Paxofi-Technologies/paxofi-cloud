<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Migrations;

use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

final class SqlStatementSplitterTest extends TestCase
{
    public function testSplitsOnTopLevelSemicolonsAndDropsComments(): void
    {
        $sql = <<<'SQL'
            -- Create the table
            CREATE TABLE a (id INT); # trailing comment
            /* block; comment */
            INSERT INTO a VALUES (1);

            SQL;

        self::assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], (new SqlStatementSplitter())->split($sql));
    }

    public function testIgnoresSemicolonsInsideQuotes(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b', \"c;d\", 'it''s; fine', 'esc\\'; still');\nCREATE TABLE `odd;name` (x INT)";

        self::assertSame([
            "INSERT INTO t VALUES ('a;b', \"c;d\", 'it''s; fine', 'esc\\'; still')",
            'CREATE TABLE `odd;name` (x INT)',
        ], (new SqlStatementSplitter())->split($sql));
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 1--1'], (new SqlStatementSplitter())->split('SELECT 1--1;'));
    }

    public function testRejectsDelimiterAndUnterminatedInput(): void
    {
        $splitter = new SqlStatementSplitter();
        foreach (["DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END //", "SELECT 'open", 'SELECT 1 /* open'] as $sql) {
            try {
                $splitter->split($sql);
                self::fail('Expected MigrationError for: ' . $sql);
            } catch (MigrationError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
