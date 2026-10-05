<?php

declare(strict_types=1);

namespace PaxofiCloud\Bootstrap;

use Paxofi\Core\Application;
use Paxofi\Core\Bootstrap\ApplicationFactory;
use Paxofi\Core\Configuration\Bootstrap as ConfigurationBootstrap;
use Paxofi\Core\Configuration\Provider as ConfigurationProvider;
use Paxofi\Core\Configuration\Validator;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Infrastructure\Provider as InfrastructureProvider;
use PaxofiCloud\Infrastructure\Providers\HealthProvider;
use PaxofiCloud\Infrastructure\Providers\HttpProvider;
use PaxofiCloud\Infrastructure\Providers\PathsProvider;

/**
 * Builds the PaxofiCloud application on PCF. Shared by the web front
 * controller, the CLI and integration tests so all three boot identically.
 */
final class AppFactory
{
    /** Environment variables without which the application must not start. */
    public const array REQUIRED_ENVIRONMENT = ['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST'];

    /**
     * @param list<Provider> $extraProviders
     */
    public static function create(string $baseDirectory, array $extraProviders = []): Application
    {
        $configuration = ConfigurationBootstrap::fromDirectory(
            $baseDirectory,
            useCache: self::isProduction(),
        );
        (new Validator())->required($configuration->environment(), self::REQUIRED_ENVIRONMENT);

        return (new ApplicationFactory())->create(null, [
            // Order matters: PCF's infrastructure provider reads the configuration.
            ConfigurationProvider::fromBootstrap($configuration),
            new InfrastructureProvider(),
            new PathsProvider(new AppPaths($baseDirectory)),
            new HealthProvider(),
            new HttpProvider(),
            ...$extraProviders,
        ]);
    }

    private static function isProduction(): bool
    {
        $environment = getenv('APP_ENV');

        return $environment === 'production' || $environment === 'prod';
    }
}
