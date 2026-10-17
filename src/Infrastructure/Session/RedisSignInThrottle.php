<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\SignInThrottle;

/**
 * Sign-in failure counters (threat model T-07, T-08, §6.11). Per pair: failures
 * within 15 minutes; from the 5th, a lock of 30 s × 2^(n−5), at most 15 min.
 * Per account: failures within the hour. Each update is one Lua script, so a
 * counter never exists without its TTL. Keys are hashes only.
 */
final readonly class RedisSignInThrottle implements SignInThrottle
{
    public const int FAILURE_WINDOW_SECONDS = 900;
    public const int FREE_FAILURES = 4;
    public const int BASE_LOCK_SECONDS = 30;
    public const int MAX_LOCK_SECONDS = 900;
    public const int ACCOUNT_WINDOW_SECONDS = 3600;

    private const string FAILURE_SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 or redis.call('TTL', KEYS[1]) < 0 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        local free = tonumber(ARGV[2])
        if count <= free then
          return 0
        end
        local lock = math.floor(tonumber(ARGV[3]) * (2 ^ math.min(count - free - 1, 20)))
        lock = math.min(lock, tonumber(ARGV[4]))
        redis.call('SET', KEYS[2], '1', 'EX', lock)
        return lock
        LUA;

    private const string COUNT_SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 or redis.call('TTL', KEYS[1]) < 0 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return count
        LUA;

    private const string TTL_SCRIPT = <<<'LUA'
        return redis.call('TTL', KEYS[1])
        LUA;

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:signin:')
    {
    }

    public function retryAfter(string $pair): int
    {
        $ttl = $this->redis->evaluate(self::TTL_SCRIPT, [$this->key('lock', $pair)]);
        if (!is_numeric($ttl)) {
            throw new \UnexpectedValueException('Unexpected throttle reply from Redis.');
        }

        // -2: no lock. -1 (no TTL) cannot happen through this class; treat it as locked briefly.
        return match (true) {
            (int) $ttl > 0 => (int) $ttl,
            (int) $ttl === -1 => self::BASE_LOCK_SECONDS,
            default => 0,
        };
    }

    public function recordFailure(string $pair): int
    {
        $lock = $this->redis->evaluate(
            self::FAILURE_SCRIPT,
            [$this->key('fail', $pair), $this->key('lock', $pair)],
            [(string) self::FAILURE_WINDOW_SECONDS, (string) self::FREE_FAILURES, (string) self::BASE_LOCK_SECONDS, (string) self::MAX_LOCK_SECONDS],
        );
        if (!is_numeric($lock)) {
            throw new \UnexpectedValueException('Unexpected throttle reply from Redis.');
        }

        return (int) $lock;
    }

    public function recordAccountFailure(string $emailLookup): int
    {
        $count = $this->redis->evaluate(self::COUNT_SCRIPT, [$this->key('acct', $emailLookup)], [(string) self::ACCOUNT_WINDOW_SECONDS]);
        if (!is_numeric($count)) {
            throw new \UnexpectedValueException('Unexpected throttle reply from Redis.');
        }

        return (int) $count;
    }

    public function clear(string $pair): void
    {
        $this->redis->delete($this->key('fail', $pair));
        $this->redis->delete($this->key('lock', $pair));
    }

    private function key(string $kind, string $hash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('Throttle keys must be SHA-256 hex digests.');
        }

        return $this->prefix . $kind . ':' . $hash;
    }
}
