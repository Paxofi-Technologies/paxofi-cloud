<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\RateLimit;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\RateLimiter;

/**
 * Fixed-window counters in Redis. INCR and the first EXPIRE run in one Lua
 * script, so a crash between them can never leave a counter without a TTL.
 * Keys are callers' hashes (client IP, email hash), never raw personal data.
 */
final readonly class RedisRateLimiter implements RateLimiter
{
    private const string SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        local ttl = redis.call('TTL', KEYS[1])
        if ttl < 0 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
          ttl = tonumber(ARGV[1])
        end
        return {count, ttl}
        LUA;

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:ratelimit:')
    {
    }

    public function hit(string $key, int $limit, int $windowSeconds): int
    {
        if ($limit < 1 || $windowSeconds < 1 || preg_match('/^[A-Za-z0-9:._-]{1,200}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid rate-limit key, limit or window.');
        }
        $result = $this->redis->evaluate(self::SCRIPT, [$this->prefix . $key], [(string) $windowSeconds]);
        if (!is_array($result) || !is_numeric($result[0] ?? null) || !is_numeric($result[1] ?? null)) {
            throw new \UnexpectedValueException('Unexpected rate-limit reply from Redis.');
        }

        return (int) $result[0] > $limit ? max(1, (int) $result[1]) : 0;
    }
}
