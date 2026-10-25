<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Database;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * What the runtime MySQL user may do, table by table (identity threat model
 * §6.22, D-14). Applied by the schema owner after every migration: everything
 * the runtime user holds on the schema is revoked, then granted again from
 * POLICY. A table missing from POLICY stops the release (deny by default).
 */
final readonly class RuntimeGrants
{
    private const array CRUD = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /** @var array<string, list<string>> table => privileges; [] means no access */
    public const array POLICY = [
        'account_members' => self::CRUD,
        'accounts' => self::CRUD,
        // Billing details (commerce CD-21): saved in place, never deleted (invoices snapshot them).
        'account_billing_details' => ['SELECT', 'INSERT', 'UPDATE'],
        // Tax table (CD-22): append-only and read-only for the runtime user until the Sprint 3 editor.
        'tax_rates' => ['SELECT'],
        // Orders and invoices (CD-24): lines are written once; on the headers only the status may change.
        'document_sequences' => ['SELECT', 'INSERT', 'UPDATE'],
        'orders' => ['SELECT', 'INSERT', 'UPDATE (status, status_changed_at)'],
        'order_lines' => ['SELECT', 'INSERT'],
        'invoices' => ['SELECT', 'INSERT', 'UPDATE (status, status_changed_at)'],
        'invoice_lines' => ['SELECT', 'INSERT'],
        // Append-only: events can be written and read, never changed or removed (T-39).
        'audit_events' => ['SELECT', 'INSERT'],
        // The single head row only moves forward.
        'audit_chain_head' => ['SELECT', 'UPDATE'],
        // DELETE is the 180-day privacy purge (T-51); the event keeps its commitment.
        'audit_event_context' => ['SELECT', 'INSERT', 'DELETE'],
        // Catalogue (commerce threat model CD-4, CD-5). Dated price and cost rows
        // are append-only: a change is a new row from a future date (PRC-005).
        'catalogue_costs' => ['SELECT', 'INSERT'],
        'catalogue_option_choices' => self::CRUD,
        'catalogue_option_prices' => ['SELECT', 'INSERT'],
        'catalogue_plan_cycles' => self::CRUD,
        'catalogue_plan_options' => self::CRUD,
        'catalogue_plans' => self::CRUD,
        'catalogue_prices' => ['SELECT', 'INSERT'],
        'catalogue_products' => self::CRUD,
        'catalogue_provider_mappings' => self::CRUD,
        'email_outbox' => self::CRUD,
        // Exchange rates (CD-16): a held set is never edited; confirmations are new sets.
        'fx_rate_sets' => ['SELECT', 'INSERT'],
        'fx_rate_values' => ['SELECT', 'INSERT'],
        // Domain cost snapshots and sync runs (CD-13, DR-8): written once, never edited.
        'domain_cost_snapshots' => ['SELECT', 'INSERT'],
        'domain_cost_items' => ['SELECT', 'INSERT'],
        'domain_cost_skipped' => ['SELECT', 'INSERT'],
        'domain_cost_feed_fields' => ['SELECT', 'INSERT'],
        'provider_sync_runs' => ['SELECT', 'INSERT'],
        // Domain pricing settings and per-extension settings (DX-5, DX-9, DX-10): edited in place, every change audited.
        'domain_pricing_settings' => ['SELECT', 'INSERT', 'UPDATE'],
        'domain_extension_settings' => ['SELECT', 'INSERT', 'UPDATE'],
        // Spaceship price checks (SP-7): written once, never edited.
        'spaceship_price_checks' => ['SELECT', 'INSERT'],
        'spaceship_price_check_items' => ['SELECT', 'INSERT'],
        // "Sync now" requests (DR-12): UPDATE only so the provider worker can mark a claim.
        'provider_sync_requests' => ['SELECT', 'INSERT', 'UPDATE'],
        'email_verification_tokens' => self::CRUD,
        'identities' => self::CRUD,
        'identity_devices' => self::CRUD,
        'identity_recovery_codes' => self::CRUD,
        'identity_totp' => self::CRUD,
        // Sealed boxes waiting for the provider worker (PC-5): replaced by delete + insert, never edited.
        'provider_credential_intake' => ['SELECT', 'INSERT', 'DELETE'],
        'provider_credentials' => self::CRUD,
        // Migration bookkeeping belongs to the owner alone.
        'schema_migrations' => [],
        'staff_invitations' => self::CRUD,
        'staff_members' => self::CRUD,
        'staff_passkeys' => self::CRUD,
        'staff_recovery_codes' => self::CRUD,
        'staff_role_assignments' => self::CRUD,
        'staff_totp' => self::CRUD,
    ];

    private const string IDENTIFIER = '/^[a-z0-9_]{1,64}$/';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    /**
     * @return array{tables: int, granted: int}
     * @throws GrantsRefused
     */
    public function apply(string $runtimeUser): array
    {
        if (preg_match(self::IDENTIFIER, $runtimeUser) !== 1) {
            throw new GrantsRefused('runtime user name must match ' . self::IDENTIFIER);
        }
        $schema = Row::string($this->repository->fetchAll('SELECT DATABASE() AS name')[0] ?? [], 'name');
        if (preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new GrantsRefused('no valid schema selected');
        }
        $tables = array_map(
            static fn (array $row): string => Row::string($row, 'name'),
            $this->repository->fetchAll(
                "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
                ['schema' => $schema],
            ),
        );
        $unknown = array_values(array_diff($tables, array_keys(self::POLICY)));
        if ($unknown !== []) {
            throw new GrantsRefused('tables without a runtime grant policy: ' . implode(', ', array_map(self::safe(...), $unknown)));
        }

        $user = "'" . $runtimeUser . "'@'%'";
        $granted = 0;
        // Reset first, within the owner's own schema (the owner has no global privileges).
        $statements = [];
        foreach (array_merge(['*'], $tables) as $table) {
            $level = '`' . $schema . '`.' . ($table === '*' ? '*' : '`' . self::identifier($table) . '`');
            $statements[] = 'REVOKE IF EXISTS ALL PRIVILEGES ON ' . $level . ' FROM ' . $user . ' IGNORE UNKNOWN USER';
            $statements[] = 'REVOKE IF EXISTS GRANT OPTION ON ' . $level . ' FROM ' . $user . ' IGNORE UNKNOWN USER';
        }
        foreach ($tables as $table) {
            $privileges = self::POLICY[$table];
            if ($privileges === []) {
                continue;
            }
            $statements[] = 'GRANT ' . implode(', ', $privileges) . ' ON `' . $schema . '`.`' . $table . '` TO ' . $user;
            $granted++;
        }
        foreach ($statements as $statement) {
            // Identifiers are validated above and privileges are constants (§6.22).
            $this->connection->execute($statement);
        }

        return ['tables' => count($tables), 'granted' => $granted];
    }

    private static function identifier(string $name): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new GrantsRefused('unexpected table name');
        }

        return $name;
    }

    private static function safe(string $name): string
    {
        return preg_match(self::IDENTIFIER, $name) === 1 ? $name : '(invalid name)';
    }
}
