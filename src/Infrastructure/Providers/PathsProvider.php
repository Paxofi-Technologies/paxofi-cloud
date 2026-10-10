<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Provider;
use PaxofiCloud\Bootstrap\AppPaths;

final readonly class PathsProvider implements Provider
{
    public function __construct(private AppPaths $paths)
    {
    }

    public function register(Application $application): void
    {
        $application->container()->instance(AppPaths::class, $this->paths);
    }

    public function boot(Application $application): void
    {
    }
}
