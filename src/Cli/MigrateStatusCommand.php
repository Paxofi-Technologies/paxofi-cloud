<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\MigrationState;
use PaxofiCloud\Infrastructure\Migrations\Migrator;

/**
 * `bin/paxoficloud migrate:status` — exits 0 when everything is applied,
 * 2 when migrations are pending, 1 when history is inconsistent (deploy
 * pipelines can gate on this).
 */
final readonly class MigrateStatusCommand implements Command
{
    public function __construct(
        private Migrator $migrator,
        private MigrationLoader $loader,
        private string $directory,
        private Console $console,
    ) {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function execute(array $arguments): int
    {
        try {
            $rows = $this->migrator->status($this->loader->load($this->directory));
        } catch (MigrationError $error) {
            $this->console->error('Cannot read migrations: ' . $error->getMessage());

            return 1;
        }

        $exit = 0;
        foreach ($rows as $row) {
            $this->console->line(sprintf('%-9s %s_%s %s', $row['state']->value, $row['version'], $row['name'], $row['applied_at'] ?? ''));
            if ($row['state'] === MigrationState::Modified || $row['state'] === MigrationState::Missing) {
                $exit = 1;
            } elseif ($row['state'] === MigrationState::Pending && $exit === 0) {
                $exit = 2;
            }
        }
        if ($rows === []) {
            $this->console->line('No migrations.');
        }

        return $exit;
    }
}
