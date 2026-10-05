<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Container;

use LogicException;
use Paxofi\Core\Contracts\Container;

/**
 * Typed container lookup for providers and composition roots only (never in
 * domain or application code). Fails loudly in production, unlike assert().
 */
final class Resolve
{
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function get(Container $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new LogicException(sprintf('Container entry %s resolved to %s.', $id, get_debug_type($service)));
        }

        return $service;
    }
}
