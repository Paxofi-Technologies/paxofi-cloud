<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\StaffSessionStore;
use PaxofiCloud\Application\Staff\Session\StaffSession;

/**
 * Staff sessions under `pc:staff:sess:{SHA-256}` (§6.18), never mixed with
 * customer sessions. A per-member set `pc:staff:sess:idx:{staff}` lists the
 * record keys for the session list (§6.25); it is written in the same call as
 * the record, expires with the absolute lifetime and is pruned on read.
 */
final readonly class RedisStaffSessionStore implements StaffSessionStore
{
    private const string TIME = 'Y-m-d\TH:i:s.uP';
    private const int INDEX_TTL_SECONDS = 28_800;

    /** Record and index entry in one call, so a session is never missing from its member's list. */
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

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:staff:sess:')
    {
    }

    public function save(StaffSession $session, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            $this->delete($session->idHash);

            return;
        }
        $record = json_encode([
            'staff_id' => $session->staffId,
            'csrf_token' => $session->csrfToken,
            'created_at' => $session->createdAt->format(self::TIME),
            'last_seen_at' => $session->lastSeenAt->format(self::TIME),
            'client_ip' => $session->clientIp,
            'user_agent' => $session->userAgent,
            'location' => $session->location,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->redis->evaluate(
            self::SAVE_SCRIPT,
            [$this->key($session->idHash), $this->indexKey($session->staffId)],
            [$record, (string) $ttlSeconds, $session->idHash, (string) self::INDEX_TTL_SECONDS],
        );
    }

    public function find(string $sessionIdHash): ?StaffSession
    {
        $raw = $this->redis->get($this->key($sessionIdHash));

        return $raw === null ? null : $this->decode($sessionIdHash, $raw);
    }

    public function forStaff(string $staffId): array
    {
        $reply = $this->redis->evaluate(self::LIST_SCRIPT, [$this->indexKey($staffId)], [$this->prefix]);
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
            // The record names its own member; an index entry can never show someone else's session.
            if ($session !== null && $session->staffId === $staffId) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    public function delete(string $sessionIdHash): void
    {
        $this->redis->delete($this->key($sessionIdHash));
    }

    private function decode(string $sessionIdHash, string $raw): ?StaffSession
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
        foreach (['staff_id', 'csrf_token', 'created_at', 'last_seen_at'] as $field) {
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
        // Sessions saved before §6.25 have no client fields; they show as unknown.
        $text = static fn (string $name): string => is_string($data[$name] ?? null) ? $data[$name] : '';

        return new StaffSession($sessionIdHash, $fields['staff_id'], $fields['csrf_token'], $created, $seen, $text('client_ip'), $text('user_agent'), $text('location'));
    }

    private function indexKey(string $staffId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $staffId) !== 1) {
            throw new \InvalidArgumentException('Unexpected staff ID.');
        }

        return $this->prefix . 'idx:' . $staffId;
    }

    private function key(string $sessionIdHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sessionIdHash) !== 1) {
            throw new \InvalidArgumentException('Staff session key must be a SHA-256 hex digest.');
        }

        return $this->prefix . $sessionIdHash;
    }
}
