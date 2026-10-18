<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\StaffSessionStore;
use PaxofiCloud\Application\Staff\Session\StaffSession;

/** Staff sessions under `pc:staff:sess:{SHA-256}` (§6.18), never mixed with customer sessions. */
final readonly class RedisStaffSessionStore implements StaffSessionStore
{
    private const string TIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:staff:sess:')
    {
    }

    public function save(StaffSession $session, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            $this->delete($session->idHash);

            return;
        }
        $this->redis->setEx($this->key($session->idHash), $ttlSeconds, json_encode([
            'staff_id' => $session->staffId,
            'csrf_token' => $session->csrfToken,
            'created_at' => $session->createdAt->format(self::TIME),
            'last_seen_at' => $session->lastSeenAt->format(self::TIME),
        ], JSON_THROW_ON_ERROR));
    }

    public function find(string $sessionIdHash): ?StaffSession
    {
        $raw = $this->redis->get($this->key($sessionIdHash));
        if ($raw === null) {
            return null;
        }
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

        return new StaffSession($sessionIdHash, $fields['staff_id'], $fields['csrf_token'], $created, $seen);
    }

    public function delete(string $sessionIdHash): void
    {
        $this->redis->delete($this->key($sessionIdHash));
    }

    private function key(string $sessionIdHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sessionIdHash) !== 1) {
            throw new \InvalidArgumentException('Staff session key must be a SHA-256 hex digest.');
        }

        return $this->prefix . $sessionIdHash;
    }
}
