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
        $pdo->exec('DROP TABLE IF EXISTS example_accounts');
        $pdo->exec('DROP TABLE IF EXISTS provider_credentials');
        $pdo->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    protected static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
