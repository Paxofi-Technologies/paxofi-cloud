<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Observability\HealthRegistry;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Health\DatabaseHealthCheck;
use PaxofiCloud\Infrastructure\Health\RedisHealthCheck;

final class HealthProvider implements Provider
{
    public function register(Application $application): void
    {
        $application->container()->singleton(HealthRegistry::class, static function (Container $c): HealthRegistry {
            $registry = new HealthRegistry();
            $registry->register(new DatabaseHealthCheck(Resolve::get($c, Repository::class)));
            $registry->register(new RedisHealthCheck(Resolve::get($c, RedisClient::class)));

            return $registry;
        });
    }

    public function boot(Application $application): void
    {
    }
}
