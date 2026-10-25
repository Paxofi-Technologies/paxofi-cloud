<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\RedisClient;

/** Key/value part of Redis for unit tests; expiry is recorded, not enforced. Lists and scripts are not supported. */
final class InMemoryRedis implements RedisClient
{
    /** @var array<string, string> */
    public array $values = [];
    /** @var array<string, int> */
    public array $ttl = [];

    /** @var array<string, list<string>> */
    private array $lists = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function setEx(string $key, int $seconds, string $value): void
    {
        $this->values[$key] = $value;
        $this->ttl[$key] = $seconds;
    }

    public function setIfAbsentEx(string $key, int $seconds, string $value): bool
    {
        if (isset($this->values[$key])) {
            return false;
        }
        $this->setEx($key, $seconds, $value);

        return true;
    }

    public function expire(string $key, int $seconds): void
    {
        $this->ttl[$key] = $seconds;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key], $this->ttl[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function pushLeft(string $key, string $value): void
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);
    }

    public function popRight(string $key): ?string
    {
        return isset($this->lists[$key]) && $this->lists[$key] !== [] ? array_pop($this->lists[$key]) : null;
    }

    public function moveRightToLeft(string $source, string $destination): ?string
    {
        throw new \LogicException('Not supported.');
    }

    public function remove(string $key, string $value): int
    {
        throw new \LogicException('Not supported.');
    }

    public function evaluate(string $script, array $keys = [], array $arguments = []): mixed
    {
        throw new \LogicException('Not supported.');
    }

    public function length(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }
}
