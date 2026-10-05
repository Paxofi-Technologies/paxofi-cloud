<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\CLI\CommandRegistry;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppPaths;
use PaxofiCloud\Cli\Console;
use PaxofiCloud\Cli\MigrateCommand;
use PaxofiCloud\Cli\MigrateStatusCommand;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

final readonly class CliProvider implements Provider
{
    public function __construct(private Console $console)
    {
    }

    public function register(Application $application): void
    {
        $container = $application->container();
        $console = $this->console;

        $container->singleton(Migrator::class, static function (Container $c): Migrator {
            $repository = Resolve::get($c, Repository::class);

            return new Migrator(Resolve::get($c, Connection::class), $repository, new MysqlAdvisoryLock($repository));
        });

        $container->singleton(CommandRegistry::class, static function (Container $c) use ($console): CommandRegistry {
            $migrator = Resolve::get($c, Migrator::class);
            $directory = Resolve::get($c, AppPaths::class)->migrations();
            $loader = new MigrationLoader();

            $registry = new CommandRegistry();
            $registry->register(new MigrateCommand($migrator, $loader, $directory, $console));
            $registry->register(new MigrateStatusCommand($migrator, $loader, $directory, $console));

            return $registry;
        });
    }

    public function boot(Application $application): void
    {
    }
}
