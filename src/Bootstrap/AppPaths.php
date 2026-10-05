<?php

declare(strict_types=1);

namespace PaxofiCloud\Bootstrap;

/**
 * Absolute paths of the deployment, bound in the container so nothing has to
 * guess its location with __DIR__ arithmetic.
 */
final readonly class AppPaths
{
    public function __construct(public string $base)
    {
    }

    public function migrations(): string
    {
        return $this->base . '/migrations';
    }
}
