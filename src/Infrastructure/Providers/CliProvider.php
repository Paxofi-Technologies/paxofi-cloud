<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\CLI\CommandRegistry;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppPaths;
use PaxofiCloud\Cli\AuditPurgeContextCommand;
use PaxofiCloud\Cli\AuditVerifyCommand;
use PaxofiCloud\Cli\Console;
use PaxofiCloud\Cli\CredentialsListCommand;
use PaxofiCloud\Cli\CredentialsReencryptCommand;
use PaxofiCloud\Cli\CredentialsSetCommand;
use PaxofiCloud\Cli\CredentialStoreAccess;
use PaxofiCloud\Cli\MailKeygenCommand;
use PaxofiCloud\Cli\MailStatusCommand;
use PaxofiCloud\Cli\MailWorkCommand;
use PaxofiCloud\Cli\MigrateCommand;
use PaxofiCloud\Cli\MigrateStatusCommand;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditChain;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\EmailWorker;
use PaxofiCloud\Infrastructure\Email\MailWorkerFactory;
use PaxofiCloud\Infrastructure\Email\MysqlOutboxReport;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Infrastructure\Time\SystemClock;

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

            // The key ring is read from the environment only when a credentials
            // command runs, so hosts without the keys can still migrate.
            $credentials = new CredentialStoreAccess(static fn (): MysqlProviderCredentialStore => new MysqlProviderCredentialStore(
                Resolve::get($c, Connection::class),
                Resolve::get($c, Repository::class),
                new SecretCipher(KeyRing::fromProcessEnvironment()),
            ));
            $clock = new SystemClock();

            $registry = new CommandRegistry();
            $registry->register(new MigrateCommand($migrator, $loader, $directory, $console));
            $registry->register(new MigrateStatusCommand($migrator, $loader, $directory, $console));
            $registry->register(new CredentialsSetCommand($credentials, $console, $clock));
            $registry->register(new CredentialsListCommand($credentials, $console, $clock));
            $registry->register(new CredentialsReencryptCommand($credentials, $console));
            $registry->register(new MailWorkCommand(static fn (): EmailWorker => MailWorkerFactory::create(
                Resolve::get($c, Configuration::class),
                Resolve::get($c, Connection::class),
                Resolve::get($c, Repository::class),
                Resolve::get($c, Logger::class),
            ), $console));
            $registry->register(new MailKeygenCommand($console));
            $registry->register(new MailStatusCommand(new MysqlOutboxReport(Resolve::get($c, Repository::class)), $console));
            $auditChain = new MysqlAuditChain(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class));
            $registry->register(new AuditVerifyCommand($auditChain, $console));
            $registry->register(new AuditPurgeContextCommand($auditChain, $console, $clock));

            return $registry;
        });
    }

    public function boot(Application $application): void
    {
    }
}
