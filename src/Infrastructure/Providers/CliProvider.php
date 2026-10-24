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
use PaxofiCloud\Cli\StaffBootstrapCommand;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Cli\Console;
use PaxofiCloud\Cli\DbGrantsCommand;
use PaxofiCloud\Cli\FxStatusCommand;
use PaxofiCloud\Cli\FxUpdateCommand;
use PaxofiCloud\Cli\ProvidersWorkCommand;
use PaxofiCloud\Application\Registrar\DomainCostSync;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Application\Staff\StaffManagerAlerts;
use PaxofiCloud\Cli\PricesStatusCommand;
use PaxofiCloud\Cli\PricesSyncCommand;
use PaxofiCloud\Domain\Registrar\CostQuarantinePolicy;
use PaxofiCloud\Infrastructure\Database\MysqlNamedLock;
use PaxofiCloud\Infrastructure\Registrar\MysqlDomainCostStore;
use PaxofiCloud\Infrastructure\Registrar\MysqlProviderSyncRequests;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiClient;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiEnvironment;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;
use PaxofiCloud\Infrastructure\Secrets\MysqlCredentialIntake;
use PaxofiCloud\Infrastructure\Secrets\SealedBox;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Pricing\FxRateUpdater;
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
use PaxofiCloud\Infrastructure\Database\RuntimeGrants;
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
            $registry->register(new DbGrantsCommand(new RuntimeGrants(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)), $console));
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
            $registry->register(new FxUpdateCommand(static fn (): FxRateUpdater => Resolve::get($c, FxRateUpdater::class), $console));
            $registry->register(new FxStatusCommand(Resolve::get($c, FxRateStore::class), $console));
            $appEnv = Resolve::get($c, Configuration::class)->get('app.env');
            $appEnv = is_string($appEnv) ? $appEnv : '';
            $costStore = new MysqlDomainCostStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class), Resolve::get($c, TransactionManager::class));
            // Domain cost sync (THREAT-MODEL-DOMAIN-REGISTRAR.md): built only when it runs, in the
            // provider worker, because it needs the KEK to open the registrar credential.
            $domainCostSync = static function () use ($c, $appEnv, $costStore): DomainCostSync {
                $environment = DomainNameApiEnvironment::fromSetting((string) getenv('DOMAINNAMEAPI_ENVIRONMENT'), $appEnv);
                $audit = Resolve::get($c, AuditTrail::class);
                $logger = Resolve::get($c, Logger::class);
                $guard = new RegistrarWriteGuard(RegistrarWriteGuard::writesEnabled((string) getenv('REGISTRAR_WRITES'), $appEnv), $audit);
                $feed = new DomainNameApiClient(
                    new ProviderHttpClient([DomainNameApiClient::endpoint($environment)], connectTimeoutMs: 5_000, totalTimeoutMs: 30_000, maxResponseBytes: 1_048_576),
                    new MysqlProviderCredentialStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class), new SecretCipher(KeyRing::fromProcessEnvironment())),
                    ProviderConnection::DomainNameApi->credentialId(ProviderConnection::environmentFor($appEnv)),
                    $environment,
                    $guard,
                );

                return new DomainCostSync(
                    $feed,
                    $costStore,
                    new MysqlNamedLock(Resolve::get($c, Repository::class)),
                    new CostQuarantinePolicy(),
                    new StaffManagerAlerts(Resolve::get($c, StaffDirectory::class), Resolve::get($c, EmailOutbox::class), $logger),
                    $audit,
                    new SystemClock(),
                    $logger,
                    static function (int $seconds): void {
                        sleep($seconds);
                    },
                    new MysqlProviderSyncRequests(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                );
            };
            $registry->register(new PricesSyncCommand($domainCostSync, $console));
            $registry->register(new PricesStatusCommand(
                $costStore,
                $console,
                DomainNameApiClient::PROVIDER,
                getenv('DOMAINNAMEAPI_ENVIRONMENT') === 'ote' ? 'ote' : 'live',
            ));
            // Provider worker (PC-6): the only process with the intake secret key and the KEK.
            $registry->register(new ProvidersWorkCommand(static function () use ($c): CredentialIntakeWorker {
                $public = getenv('CREDENTIAL_INTAKE_PUBLIC_KEY');
                $secret = getenv('CREDENTIAL_INTAKE_SECRET_KEY');
                if (!is_string($public) || !is_string($secret) || $public === '' || $secret === '') {
                    throw new \InvalidArgumentException('CREDENTIAL_INTAKE_PUBLIC_KEY and CREDENTIAL_INTAKE_SECRET_KEY must be set.');
                }
                $env = Resolve::get($c, Configuration::class)->get('app.env');
                $connection = Resolve::get($c, Connection::class);
                $repository = Resolve::get($c, Repository::class);

                return new CredentialIntakeWorker(
                    ProviderConnection::environmentFor(is_string($env) ? $env : ''),
                    new MysqlCredentialIntake($connection, $repository, Resolve::get($c, TransactionManager::class)),
                    SealedBox::forOpening($public, $secret),
                    new MysqlProviderCredentialStore($connection, $repository, new SecretCipher(KeyRing::fromProcessEnvironment())),
                    Resolve::get($c, StaffDirectory::class),
                    Resolve::get($c, EmailOutbox::class),
                    Resolve::get($c, AuditTrail::class),
                    new SystemClock(),
                    Resolve::get($c, Logger::class),
                );
            }, $console, null, $domainCostSync));
            $registry->register(new StaffBootstrapCommand(static fn (): StaffInvitations => Resolve::get($c, StaffInvitations::class), $console));

            return $registry;
        });
    }

    public function boot(Application $application): void
    {
    }
}
