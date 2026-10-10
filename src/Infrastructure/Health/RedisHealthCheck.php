<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Health;

use Paxofi\Core\Contracts\HealthCheck;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Observability\HealthResult;

/** Round-trips a short-lived key to prove Redis accepts reads and writes. */
final readonly class RedisHealthCheck implements HealthCheck
{
    private const string KEY = 'paxoficloud:health:probe';

    public function __construct(private RedisClient $redis)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthResult
    {
        $token = bin2hex(random_bytes(8));
        $this->redis->setEx(self::KEY, 10, $token);

        return $this->redis->get(self::KEY) === $token
            ? HealthResult::healthy()
            : HealthResult::unhealthy('Redis read-after-write mismatch');
    }
}
