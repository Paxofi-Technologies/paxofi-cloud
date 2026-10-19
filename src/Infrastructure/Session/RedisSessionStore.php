<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\SessionStore;
use PaxofiCloud\Application\Identity\Session\Session;

/**
 * Sessions in Redis under `pc:sess:{SHA-256 of the session ID}` (threat model
 * D-6). The TTL is the idle timeout; Sessions also checks both timeouts against
 * the stored times, so a missed expiry cannot extend a session. A per-identity
 * set `pc:sess:idx:{identity}` lists the record keys for the session list
 * (§6.21); it expires with the absolute lifetime and is pruned on read.
 */
final readonly class RedisSessionStore implements SessionStore
{
    private const string TIME = 'Y-m-d\TH:i:s.uP';
    private const int INDEX_TTL_SECONDS = 43_200;

    /** Record and index entry in one call, so a session is never missing from its owner's list. */
    private const string SAVE_SCRIPT = <<<'LUA'
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
        redis.call('SADD', KEYS[2], ARGV[3])
        redis.call('EXPIRE', KEYS[2], ARGV[4])
        return 1
        LUA;

    /** Returns [hash, record] pairs and drops index entries whose record has expired. */
    private const string LIST_SCRIPT = <<<'LUA'
        local out = {}
        for _, hash in ipairs(redis.call('SMEMBERS', KEYS[1])) do
          local value = redis.call('GET', ARGV[1] .. hash)
          if value then
            table.insert(out, hash)
            table.insert(out, value)
          else
            redis.call('SREM', KEYS[1], hash)
          end
        end
        return out
        LUA;

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:sess:')
    {
    }

    public function save(Session $session, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            $this->delete($session->idHash);

            return;
        }
        $record = json_encode([
            'identity_id' => $session->identityId,
            'account_id' => $session->accountId,
            'csrf_token' => $session->csrfToken,
            'created_at' => $session->createdAt->format(self::TIME),
            'last_seen_at' => $session->lastSeenAt->format(self::TIME),
            'client_ip' => $session->clientIp,
            'user_agent' => $session->userAgent,
            'location' => $session->location,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->redis->evaluate(
            self::SAVE_SCRIPT,
            [$this->key($session->idHash), $this->indexKey($session->identityId)],
            [$record, (string) $ttlSeconds, $session->idHash, (string) self::INDEX_TTL_SECONDS],
        );
    }

    public function find(string $sessionIdHash): ?Session
    {
        $raw = $this->redis->get($this->key($sessionIdHash));

        return $raw === null ? null : $this->decode($sessionIdHash, $raw);
    }

    public function forIdentity(string $identityId): array
    {
        $reply = $this->redis->evaluate(self::LIST_SCRIPT, [$this->indexKey($identityId)], [$this->prefix]);
        if (!is_array($reply)) {
            return [];
        }
        $sessions = [];
        for ($i = 0; $i + 1 < count($reply); $i += 2) {
            $hash = $reply[$i];
            $raw = $reply[$i + 1];
            if (!is_string($hash) || !is_string($raw) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                continue;
            }
            $session = $this->decode($hash, $raw);
            // The record names its own identity; an index entry can never show someone else's session.
            if ($session !== null && $session->identityId === $identityId) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    private function decode(string $sessionIdHash, string $raw): ?Session
    {
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $fields = [];
        foreach (['identity_id', 'account_id', 'csrf_token', 'created_at', 'last_seen_at', 'client_ip', 'user_agent'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                return null;
            }
            $fields[$field] = $data[$field];
        }
        $created = \DateTimeImmutable::createFromFormat(self::TIME, $fields['created_at']);
        $seen = \DateTimeImmutable::createFromFormat(self::TIME, $fields['last_seen_at']);
        if ($created === false || $seen === false) {
            return null;
        }

        $location = is_string($data['location'] ?? null) ? $data['location'] : '';

        return new Session($sessionIdHash, $fields['identity_id'], $fields['account_id'], $fields['csrf_token'], $created, $seen, $fields['client_ip'], $fields['user_agent'], $location);
    }

    public function delete(string $sessionIdHash): void
    {
        $this->redis->delete($this->key($sessionIdHash));
    }

    private function indexKey(string $identityId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $identityId) !== 1) {
            throw new \InvalidArgumentException('Unexpected identity ID.');
        }

        return $this->prefix . 'idx:' . $identityId;
    }

    private function key(string $sessionIdHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sessionIdHash) !== 1) {
            throw new \InvalidArgumentException('Session key must be a SHA-256 hex digest.');
        }

        return $this->prefix . $sessionIdHash;
    }
}
