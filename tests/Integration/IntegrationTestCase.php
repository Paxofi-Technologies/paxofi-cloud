<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs only when PAXOFICLOUD_INTEGRATION=1 and real MySQL/Redis are provided
 * through DB_DSN, DB_USERNAME, DB_PASSWORD and REDIS_HOST (CI job
 * "Integration (MySQL 8.4, Redis 7)"). Never point this at a shared database:
 * it drops and recreates tables.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('PAXOFICLOUD_INTEGRATION') !== '1') {
            self::markTestSkipped('Set PAXOFICLOUD_INTEGRATION=1 with MySQL and Redis to run integration tests.');
        }
        // No live calls to the breached-password API from tests (unit tests cover it).
        if (getenv('BREACHED_PASSWORD_CHECK') === false) {
            putenv('BREACHED_PASSWORD_CHECK=off');
        }
        // Fixed, test-only MFA keys (§6.13) unless the run provides its own.
        if (getenv('MFA_RECOVERY_KEY') === false) {
            putenv('MFA_SECRET_KEYS=t1:' . base64_encode(str_repeat("\x11", 32)));
            putenv('MFA_SECRET_ACTIVE_KEY=t1');
            putenv('MFA_RECOVERY_KEY=' . base64_encode(str_repeat("\x22", 32)));
        }
    }

    protected static function env(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, $key . ' must be set for integration tests');

        return $value;
    }

    protected static function pdo(): PDO
    {
        return new PDO(self::env('DB_DSN'), self::env('DB_USERNAME'), self::env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    protected static function resetDatabase(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS staff_recovery_codes');
        $pdo->exec('DROP TABLE IF EXISTS staff_totp');
        $pdo->exec('DROP TABLE IF EXISTS staff_passkeys');
        $pdo->exec('DROP TABLE IF EXISTS staff_invitations');
        $pdo->exec('DROP TABLE IF EXISTS staff_role_assignments');
        $pdo->exec('DROP TABLE IF EXISTS staff_members');
        $pdo->exec('DROP TABLE IF EXISTS audit_event_context');
        $pdo->exec('DROP TABLE IF EXISTS audit_events');
        $pdo->exec('DROP TABLE IF EXISTS audit_chain_head');
        $pdo->exec('DROP TABLE IF EXISTS identity_devices');
        $pdo->exec('DROP TABLE IF EXISTS identity_recovery_codes');
        $pdo->exec('DROP TABLE IF EXISTS identity_totp');
        $pdo->exec('DROP TABLE IF EXISTS email_verification_tokens');
        $pdo->exec('DROP TABLE IF EXISTS account_members');
        $pdo->exec('DROP TABLE IF EXISTS accounts');
        $pdo->exec('DROP TABLE IF EXISTS identities');
        $pdo->exec('DROP TABLE IF EXISTS example_accounts');
        $pdo->exec('DROP TABLE IF EXISTS email_outbox');
        $pdo->exec('DROP TABLE IF EXISTS provider_credentials');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_option_prices');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_option_choices');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_plan_options');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_costs');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_prices');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_provider_mappings');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_plan_cycles');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_plans');
        $pdo->exec('DROP TABLE IF EXISTS catalogue_products');
        $pdo->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    protected static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
