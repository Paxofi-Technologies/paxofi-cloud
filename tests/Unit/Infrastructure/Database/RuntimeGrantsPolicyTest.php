<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Database;

use PaxofiCloud\Infrastructure\Database\RuntimeGrants;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** §6.22: every table the migrations create has an explicit, reviewed runtime policy. */
final class RuntimeGrantsPolicyTest extends TestCase
{
    public function testThePolicyNamesExactlyTheTablesTheMigrationsCreate(): void
    {
        $tables = [Migrator::TABLE];
        foreach (glob(dirname(__DIR__, 4) . '/migrations/*.sql') ?: [] as $file) {
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i', (string) file_get_contents($file), $m);
            array_push($tables, ...$m[1]);
        }
        sort($tables);
        $policy = array_keys(RuntimeGrants::POLICY);
        sort($policy);

        self::assertSame($tables, $policy, 'add new tables to RuntimeGrants::POLICY (threat model §6.22)');
    }

    public function testTheAuditLogIsAppendOnlyAndMigrationsAreOffLimits(): void
    {
        self::assertSame(['SELECT', 'INSERT'], RuntimeGrants::POLICY['audit_events']);
        self::assertSame(['SELECT', 'UPDATE'], RuntimeGrants::POLICY['audit_chain_head']);
        self::assertNotContains('UPDATE', RuntimeGrants::POLICY['audit_event_context']);
        self::assertSame([], RuntimeGrants::POLICY[Migrator::TABLE]);
    }

    public function testOnlyRowLevelPrivilegesAreEverGranted(): void
    {
        foreach (RuntimeGrants::POLICY as $table => $privileges) {
            $other = array_filter($privileges, static fn (string $p): bool => !in_array($p, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)
                && preg_match('/^UPDATE \([a-z_]+(, [a-z_]+)*\)$/', $p) !== 1);
            self::assertSame([], $other, $table);
        }
    }

    public function testOrderAndInvoiceTotalsCannotBeRewritten(): void
    {
        // Commerce CD-24, C-44: lines are append-only; headers may change status only.
        foreach (['orders', 'invoices'] as $table) {
            self::assertSame(['SELECT', 'INSERT', 'UPDATE (status, status_changed_at)'], RuntimeGrants::POLICY[$table], $table);
        }
        foreach (['order_lines', 'invoice_lines'] as $table) {
            self::assertSame(['SELECT', 'INSERT'], RuntimeGrants::POLICY[$table], $table);
        }
        self::assertSame(['SELECT'], RuntimeGrants::POLICY['tax_rates']);
    }
}
