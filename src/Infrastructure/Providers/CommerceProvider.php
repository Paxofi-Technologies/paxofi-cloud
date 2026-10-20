<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
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
    }

    public function boot(Application $application): void
    {
    }
}
