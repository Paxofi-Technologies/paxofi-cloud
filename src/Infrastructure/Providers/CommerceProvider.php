<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Pricing\FxRateUpdater;
use PaxofiCloud\Domain\Pricing\FxRatePolicy;
use PaxofiCloud\Infrastructure\Pricing\MysqlFxRateStore;
use PaxofiCloud\Integration\Fx\ExchangeRateApiSource;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Application\Catalogue\PublicCatalogue;
use PaxofiCloud\Application\Contracts\CatalogueStore;
use PaxofiCloud\Application\Cart\DomainCart;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Controllers\CartController;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Billing\BillingDetailsService;
use PaxofiCloud\Http\Controllers\BillingDetailsController;
use PaxofiCloud\Application\Checkout\Checkout;
use PaxofiCloud\Http\Controllers\CheckoutController;
use PaxofiCloud\Http\Controllers\OrdersController;
use PaxofiCloud\Http\Controllers\PaymentsController;
use PaxofiCloud\Http\Controllers\WebhookController;
use PaxofiCloud\Application\Payments\WebhookReceiver;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Infrastructure\Payments\ConfiguredPaymentGateways;
use PaxofiCloud\Infrastructure\Payments\MysqlGatewayEventStore;
use PaxofiCloud\Infrastructure\Secrets\CredentialTable;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Application\Payments\InvoicePayments;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PaxofiCloud\Infrastructure\Payments\MysqlPaymentAttemptStore;
use PaxofiCloud\Infrastructure\Ledger\MysqlLedger;
use PaxofiCloud\Infrastructure\Orders\MysqlOrderStore;
use Paxofi\Core\Contracts\Configuration;
use PaxofiCloud\Infrastructure\Billing\MysqlBillingStore;
use PaxofiCloud\Infrastructure\Billing\MysqlTaxRateStore;
use PaxofiCloud\Http\Controllers\CatalogueController;
use PaxofiCloud\Http\Controllers\DomainCheckController;
use PaxofiCloud\Http\Controllers\DomainPricesController;
use PaxofiCloud\Http\Controllers\DomainSearchController;
use PaxofiCloud\Application\Domains\DomainPriceList;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Application\Domains\DomainSearch;
use PaxofiCloud\Application\Domains\RdapAvailability;
use PaxofiCloud\Infrastructure\Domains\MysqlDomainSearchSettingsStore;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Infrastructure\Domains\CachedRdapDirectory;
use PaxofiCloud\Infrastructure\Extensions\MysqlExtensionSettingsStore;
use PaxofiCloud\Infrastructure\Registrar\MysqlDomainCostStore;
use PaxofiCloud\Integration\Rdap\RdapBootstrap;
use PaxofiCloud\Integration\Rdap\RdapLookup;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiClient;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapClient;
use PaxofiCloud\Integration\Registrar\Dynadot\DynadotClient;
use PaxofiCloud\Infrastructure\Catalogue\MysqlCatalogueStore;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\RateLimit\RedisRateLimiter;
use PaxofiCloud\Infrastructure\Time\SystemClock;

/** Catalogue, pricing, cart and checkout wiring (Sprint 2; commerce threat model). */
final class CommerceProvider implements Provider
{
    public function register(Application $application): void
    {
        $container = $application->container();
        $container->singleton(CatalogueStore::class, static fn (Container $c): CatalogueStore => new MysqlCatalogueStore(Resolve::get($c, Repository::class)));
        $container->singleton(PublicCatalogue::class, static fn (Container $c): PublicCatalogue => new PublicCatalogue(Resolve::get($c, CatalogueStore::class), new SystemClock()));
        $container->singleton(CatalogueController::class, static fn (Container $c): CatalogueController => new CatalogueController(
            Resolve::get($c, PublicCatalogue::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
        ));
        // Customer domain search (THREAT-MODEL-DOMAIN-SEARCH.md): RDAP pre-check and our selling prices; no registrar call, no key.
        $container->singleton(ExtensionQuotes::class, static function (Container $c): ExtensionQuotes {
            $repository = Resolve::get($c, Repository::class);

            return new ExtensionQuotes(
                [
                    DomainNameApiClient::PROVIDER => getenv('DOMAINNAMEAPI_ENVIRONMENT') === 'ote' ? 'ote' : 'live',
                    NamecheapClient::PROVIDER => getenv('NAMECHEAP_ENVIRONMENT') === 'sandbox' ? 'sandbox' : 'live',
                    DynadotClient::PROVIDER => getenv('DYNADOT_ENVIRONMENT') === 'sandbox' ? 'sandbox' : 'live',
                ],
                new MysqlDomainCostStore(Resolve::get($c, Connection::class), $repository, Resolve::get($c, TransactionManager::class)),
                new MysqlExtensionSettingsStore(Resolve::get($c, Connection::class), $repository),
                Resolve::get($c, FxRateStore::class),
                new SystemClock(),
            );
        });
        $container->singleton(DomainSearchController::class, static function (Container $c): DomainSearchController {
            $redis = Resolve::get($c, RedisClient::class);

            return new DomainSearchController(new DomainSearch(
                Resolve::get($c, ExtensionQuotes::class),
                self::rdapAvailability($redis),
                new RedisRateLimiter($redis),
                new MysqlDomainSearchSettingsStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
            ));
        });
        // Registrar availability check (DS-13): the web tier only queues names; the provider worker answers.
        $container->singleton(DomainCheckController::class, static fn (Container $c): DomainCheckController => new DomainCheckController(new RegistrarCheck(
            Resolve::get($c, ExtensionQuotes::class),
            Resolve::get($c, RedisClient::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
        )));
        // Signed-in domain cart (CD-20): lines only, re-quoted on every read.
        $container->singleton(CartController::class, static fn (Container $c): CartController => new CartController(
            new DomainCart(Resolve::get($c, RedisClient::class), Resolve::get($c, ExtensionQuotes::class), new RedisRateLimiter(Resolve::get($c, RedisClient::class))),
            Resolve::get($c, Sessions::class),
        ));
        // Billing details and the tax table (CD-21, CD-22): the session's account only.
        $container->singleton(BillingDetailsController::class, static fn (Container $c): BillingDetailsController => new BillingDetailsController(
            new BillingDetailsService(
                new MysqlBillingStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                new MysqlTaxRateStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
                Resolve::get($c, AuditTrail::class),
                new SystemClock(),
            ),
            Resolve::get($c, Sessions::class),
        ));
        // Ledger (PY-13): append-only journal; invoices post their issue entry in the placing transaction.
        $container->singleton(MysqlLedger::class, static fn (Container $c): MysqlLedger => new MysqlLedger(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
            Resolve::get($c, TransactionManager::class),
        ));
        $container->singleton(MysqlOrderStore::class, static fn (Container $c): MysqlOrderStore => new MysqlOrderStore(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
            Resolve::get($c, TransactionManager::class),
            Resolve::get($c, MysqlLedger::class),
        ));
        // Checkout and orders (CD-23…CD-26): server cart, registrar re-check by the worker, one-transaction placement.
        $container->singleton(CheckoutController::class, static function (Container $c): CheckoutController {
            $redis = Resolve::get($c, RedisClient::class);
            $limiter = new RedisRateLimiter($redis);
            $quotes = Resolve::get($c, ExtensionQuotes::class);
            $terms = Resolve::get($c, Configuration::class)->get('identity.terms_version');

            return new CheckoutController(new Checkout(
                new DomainCart($redis, $quotes, $limiter),
                $quotes,
                new RegistrarCheck($quotes, $redis, $limiter),
                new MysqlBillingStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                new MysqlTaxRateStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                Resolve::get($c, MysqlOrderStore::class),
                $redis,
                $limiter,
                Resolve::get($c, AuditTrail::class),
                new SystemClock(),
                is_string($terms) ? $terms : '',
            ), Resolve::get($c, Sessions::class));
        });
        $container->singleton(OrdersController::class, static fn (Container $c): OrdersController => new OrdersController(
            Resolve::get($c, MysqlOrderStore::class),
            Resolve::get($c, Sessions::class),
        ));
        // Paying invoices (payments PY-5, PY-6, PY-17): the web tier records attempts; the payments worker starts checkouts.
        $container->singleton(PaymentsController::class, static function (Container $c): PaymentsController {
            $env = Resolve::get($c, Configuration::class)->get('app.env');

            return new PaymentsController(new InvoicePayments(
                new MysqlPaymentAttemptStore(
                    Resolve::get($c, Connection::class),
                    Resolve::get($c, Repository::class),
                    Resolve::get($c, TransactionManager::class),
                    Resolve::get($c, MysqlLedger::class),
                ),
                new MysqlBillingStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
                Resolve::get($c, AuditTrail::class),
                new SystemClock(),
                ProviderConnection::environmentFor(is_string($env) ? $env : ''),
            ), Resolve::get($c, Sessions::class));
        });
        // Gateway webhooks (payments PY-3, PY-7): built only in payments-web, the one web process with the payments key ring.
        $container->singleton(WebhookController::class, static function (Container $c): WebhookController {
            $ring = KeyRing::fromNamedProcessEnvironment('PAYMENTS_CREDENTIAL_KEYS', 'PAYMENTS_CREDENTIAL_ACTIVE_KEY', '(payments-web and payments worker only)')
                ?? throw new \InvalidArgumentException('PAYMENTS_CREDENTIAL_KEYS and PAYMENTS_CREDENTIAL_ACTIVE_KEY must be set.');
            $env = Resolve::get($c, Configuration::class)->get('app.env');
            $env = is_string($env) ? $env : '';
            $connection = Resolve::get($c, Connection::class);
            $repository = Resolve::get($c, Repository::class);

            return new WebhookController(new WebhookReceiver(
                new ConfiguredPaymentGateways(
                    new MysqlProviderCredentialStore($connection, $repository, new SecretCipher($ring), CredentialTable::PaymentGateways),
                    ProviderConnection::environmentFor($env),
                    GatewayMode::forEnvironment($env),
                ),
                new MysqlGatewayEventStore($connection, Resolve::get($c, TransactionManager::class)),
                new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
                Resolve::get($c, AuditTrail::class),
                new SystemClock(),
                Resolve::get($c, Logger::class),
            ));
        });
        // Public price list (DS-11): selling prices only, cacheable.
        $container->singleton(DomainPricesController::class, static fn (Container $c): DomainPricesController => new DomainPricesController(new DomainPriceList(
            Resolve::get($c, ExtensionQuotes::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
        )));
        $container->singleton(FxRateStore::class, static fn (Container $c): FxRateStore => new MysqlFxRateStore(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
            Resolve::get($c, TransactionManager::class),
        ));
        // Only the worker fetches rates (CD-16); the web tier never calls the rate source.
        $container->singleton(FxRateUpdater::class, static fn (Container $c): FxRateUpdater => new FxRateUpdater(
            new ExchangeRateApiSource(new ProviderHttpClient([ExchangeRateApiSource::endpoint()], connectTimeoutMs: 3_000, totalTimeoutMs: 10_000, maxResponseBytes: 262_144)),
            Resolve::get($c, FxRateStore::class),
            FxRatePolicy::defaults(),
            new SystemClock(),
            Resolve::get($c, Logger::class),
        ));
    }

    /** The RDAP pre-check (DS-2, DS-3), shared by the web search and the provider worker. */
    public static function rdapAvailability(RedisClient $redis): RdapAvailability
    {
        return new RdapAvailability(
            new CachedRdapDirectory($redis, new RdapBootstrap(new ProviderHttpClient([RdapBootstrap::endpoint()], connectTimeoutMs: 3_000, totalTimeoutMs: 8_000, maxResponseBytes: RdapBootstrap::MAX_BYTES))),
            new RdapLookup(),
            $redis,
            new RedisRateLimiter($redis),
        );
    }

    public function boot(Application $application): void
    {
    }
}
