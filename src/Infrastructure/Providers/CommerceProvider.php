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
