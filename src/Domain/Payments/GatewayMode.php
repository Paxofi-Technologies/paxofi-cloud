<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Payments;

/**
 * Test or live gateway keys (PY-4). Production takes only live keys; every
 * other environment takes only test keys, so staging can exercise real
 * payment flows without moving money and production never accepts a test
 * payment as real.
 */
enum GatewayMode: string
{
    case Test = 'test';
    case Live = 'live';

    public static function forEnvironment(string $appEnv): self
    {
        return $appEnv === 'production' ? self::Live : self::Test;
    }
}
