<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\MfaChallengeStore;

/**
 * MFA tickets under `pc:mfa:t:{SHA-256}` and per-identity failure counters
 * under `pc:mfa:f:{identity}` (§6.13). Counters are INCR + EXPIRE in one Lua
 * script, so none can exist without a TTL.
 */
final readonly class RedisMfaChallengeStore implements MfaChallengeStore
{
    public const int FAILURE_WINDOW_SECONDS = 900;

    /** Counts an attempt only on a live ticket; never re-creates an expired one. */
    private const string COUNT_SCRIPT = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then
          return 0
        end
        return redis.call('HINCRBY', KEYS[1], 'attempts', 1)
        LUA;

    private const string INCR_SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 or redis.call('TTL', KEYS[1]) < 0 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return count
        LUA;

    private const string SAVE_SCRIPT = <<<'LUA'
        redis.call('DEL', KEYS[1])
        redis.call('HSET', KEYS[1], 'identity_id', ARGV[1], 'account_id', ARGV[2], 'attempts', 0)
        redis.call('EXPIRE', KEYS[1], ARGV[3])
        return 1
        LUA;

    private const string FIND_SCRIPT = <<<'LUA'
        return redis.call('HMGET', KEYS[1], 'identity_id', 'account_id', 'attempts')
        LUA;

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:mfa:')
    {
    }

    public function save(string $ticketHash, string $identityId, string $accountId, int $ttlSeconds): void
    {
        $this->failureKey($identityId); // validates the identity ID format
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('MFA tickets need a positive TTL.');
        }
        $this->redis->evaluate(self::SAVE_SCRIPT, [$this->ticketKey($ticketHash)], [$identityId, $accountId, (string) $ttlSeconds]);
    }

    public function find(string $ticketHash): ?array
    {
        $values = $this->redis->evaluate(self::FIND_SCRIPT, [$this->ticketKey($ticketHash)]);
        if (!is_array($values) || !is_string($values[0] ?? null) || !is_string($values[1] ?? null) || !is_numeric($values[2] ?? null)) {
            return null;
        }

        return ['identity_id' => $values[0], 'account_id' => $values[1], 'attempts' => (int) $values[2]];
    }

    public function recordAttempt(string $ticketHash): int
    {
        $count = $this->redis->evaluate(self::COUNT_SCRIPT, [$this->ticketKey($ticketHash)]);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function delete(string $ticketHash): void
    {
        $this->redis->delete($this->ticketKey($ticketHash));
    }

    public function identityFailures(string $identityId): int
    {
        return (int) ($this->redis->get($this->failureKey($identityId)) ?? 0);
    }

    public function recordIdentityFailure(string $identityId): int
    {
        $count = $this->redis->evaluate(self::INCR_SCRIPT, [$this->failureKey($identityId)], [(string) self::FAILURE_WINDOW_SECONDS]);
        if (!is_numeric($count)) {
            throw new \UnexpectedValueException('Unexpected MFA counter reply from Redis.');
        }

        return (int) $count;
    }

    private function ticketKey(string $hash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('MFA ticket keys must be SHA-256 hex digests.');
        }

        return $this->prefix . 't:' . $hash;
    }

    private function failureKey(string $identityId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $identityId) !== 1) {
            throw new \InvalidArgumentException('Unexpected identity ID.');
        }

        return $this->prefix . 'f:' . $identityId;
    }
}
