<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Session;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\SessionStore;
use PaxofiCloud\Application\Identity\Session\Session;

/**
 * Sessions in Redis under `pc:sess:{SHA-256 of the session ID}` (threat model
 * D-6). The TTL is the idle timeout; Sessions also checks both timeouts against
 * the stored times, so a missed expiry cannot extend a session.
 */
final readonly class RedisSessionStore implements SessionStore
{
    private const string TIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(private RedisClient $redis, private string $prefix = 'pc:sess:')
    {
    }

    public function save(Session $session, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            $this->delete($session->idHash);

            return;
        }
        $this->redis->setEx($this->key($session->idHash), $ttlSeconds, json_encode([
            'identity_id' => $session->identityId,
            'account_id' => $session->accountId,
            'csrf_token' => $session->csrfToken,
            'created_at' => $session->createdAt->format(self::TIME),
            'last_seen_at' => $session->lastSeenAt->format(self::TIME),
            'client_ip' => $session->clientIp,
            'user_agent' => $session->userAgent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function find(string $sessionIdHash): ?Session
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

        return new Session($sessionIdHash, $fields['identity_id'], $fields['account_id'], $fields['csrf_token'], $created, $seen, $fields['client_ip'], $fields['user_agent']);
    }

    public function delete(string $sessionIdHash): void
    {
        $this->redis->delete($this->key($sessionIdHash));
    }

    private function key(string $sessionIdHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sessionIdHash) !== 1) {
            throw new \InvalidArgumentException('Session key must be a SHA-256 hex digest.');
        }

        return $this->prefix . $sessionIdHash;
    }
}
