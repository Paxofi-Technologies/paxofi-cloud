<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Migrations\Migration;
use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;

/** `bin/paxoficloud migrate` — apply pending migrations in order. */
final readonly class MigrateCommand implements Command
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
        return 'migrate';
    }

    public function execute(array $arguments): int
    {
        try {
            $applied = $this->migrator->migrate(
                $this->loader->load($this->directory),
                function (Migration $migration, int $ms): void {
                    $this->console->line(sprintf('Applied %s_%s (%d ms)', $migration->version, $migration->name, $ms));
                },
            );
        } catch (MigrationError $error) {
            $this->console->error('Migration failed: ' . $error->getMessage());

            return 1;
        }

        $this->console->line($applied === [] ? 'Nothing to migrate.' : sprintf('%d migration(s) applied.', count($applied)));

        return 0;
    }
}
