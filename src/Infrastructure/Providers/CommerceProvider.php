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
use PaxofiCloud\Http\Controllers\CatalogueController;
use PaxofiCloud\Http\Controllers\DomainPricesController;
use PaxofiCloud\Http\Controllers\DomainSearchController;
use PaxofiCloud\Application\Domains\DomainPriceList;
use PaxofiCloud\Application\Domains\DomainSearch;
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
                new CachedRdapDirectory($redis, new RdapBootstrap(new ProviderHttpClient([RdapBootstrap::endpoint()], connectTimeoutMs: 3_000, totalTimeoutMs: 8_000, maxResponseBytes: RdapBootstrap::MAX_BYTES))),
                new RdapLookup(),
                $redis,
                new RedisRateLimiter($redis),
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

    public function boot(Application $application): void
    {
    }
}
