<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\CeremonyStore;

/**
 * WebAuthn ceremony state under `pc:staff:wa:{SHA-256}` (§6.17). The state is
 * stored as JSON with a TTL, and `take` reads and deletes it in one Lua call,
 * so a ceremony can never be finished twice.
 */
final readonly class RedisCeremonyStore implements CeremonyStore
{
    private const string TAKE_SCRIPT = <<<'LUA'
        local value = redis.call('GET', KEYS[1])
        if value then
          redis.call('DEL', KEYS[1])
        end
        return value
        LUA;

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:staff:wa:')
    {
    }

    public function put(string $ceremonyHash, array $state, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Ceremonies need a positive TTL.');
        }
        $this->redis->setEx($this->key($ceremonyHash), $ttlSeconds, json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function take(string $ceremonyHash): ?array
    {
        $value = $this->redis->evaluate(self::TAKE_SCRIPT, [$this->key($ceremonyHash)]);
        if (!is_string($value)) {
            return null;
        }
        $state = json_decode($value, true);
        if (!is_array($state)) {
            return null;
        }
        $clean = [];
        foreach ($state as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                return null;
            }
            $clean[$key] = $item;
        }

        return $clean;
    }

    private function key(string $hash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('Ceremony keys must be SHA-256 hex digests.');
        }

        return $this->prefix . $hash;
    }
}
