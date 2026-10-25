<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;

/**
 * The web side of the registrar availability check (THREAT-MODEL-DOMAIN-SEARCH.md
 * DS-13). The web tier never holds a registrar key (PC-6): it validates up to
 * 6 names in extensions we sell, queues them in Redis for the provider worker
 * and later reads the answer. Names live in Redis for at most 2 minutes under
 * a random job ID and are never written to the database or logs.
 */
final readonly class RegistrarCheck
{
    public const int MAX_DOMAINS = 6;
    public const int TTL_SECONDS = 120;
    public const int IP_PER_MINUTE = 10;
    public const int IP_PER_HOUR = 120;
    public const int MAX_QUEUE = 500;
    public const string QUEUE = 'pc:regcheck:queue';
    private const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private ExtensionQuotes $quotes, private RedisClient $redis, private RateLimiter $limiter)
    {
    }

    public static function jobKey(string $id): string
    {
        return 'pc:regcheck:job:' . $id;
    }

    public static function resultKey(string $id): string
    {
        return 'pc:regcheck:result:' . $id;
    }

    /**
     * @param array<array-key, mixed> $domains
     * @throws DomainSearchRefused
     */
    public function request(string $clientIp, array $domains): string
    {
        $ipKey = hash('sha256', $clientIp !== '' ? $clientIp : 'unknown');
        foreach ([[self::IP_PER_MINUTE, 60], [self::IP_PER_HOUR, 3600]] as [$limit, $window]) {
            $retry = $this->limiter->hit('regcheck:ip:' . $window . ':' . $ipKey, $limit, $window);
            if ($retry > 0) {
                throw new DomainSearchRefused(DomainSearchRefused::TOO_MANY, $retry);
            }
        }
        if (!array_is_list($domains) || $domains === [] || count($domains) > self::MAX_DOMAINS) {
            throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
        }
        $clean = [];
        foreach ($domains as $domain) {
            if (!is_string($domain) || strlen($domain) > 253) {
                throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
            }
            $domain = strtolower($domain);
            $parts = explode('.', $domain, 2);
            if (count($parts) !== 2 || preg_match(self::LABEL, $parts[0]) !== 1) {
                throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
            }
            if ($this->quotes->quote($parts[1]) === null) {
                throw new DomainSearchRefused(DomainSearchRefused::UNKNOWN_EXTENSION);
            }
            $clean[] = $domain;
        }
        if ($this->redis->length(self::QUEUE) >= self::MAX_QUEUE) {
            throw new DomainSearchRefused(DomainSearchRefused::TOO_MANY, 30);
        }
        $id = bin2hex(random_bytes(16));
        $this->redis->setEx(self::jobKey($id), self::TTL_SECONDS, json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR));
        $this->redis->setEx(self::resultKey($id), self::TTL_SECONDS, 'pending');
        $this->redis->pushLeft(self::QUEUE, $id);

        return $id;
    }

    /**
     * @phpstan-impure reads the current answer from Redis
     * @return array{status: 'pending'|'done'|'expired', results: list<array{domain: string, status: string, premium: bool, register: ?array{usd: int, ngn: ?int}}>}
     * @throws DomainSearchRefused
     */
    public function result(string $id): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
        }
        $raw = $this->redis->get(self::resultKey($id));
        if ($raw === null) {
            return ['status' => 'expired', 'results' => []];
        }
        if ($raw === 'pending') {
            return ['status' => 'pending', 'results' => []];
        }
        $decoded = json_decode($raw, true);
        $results = [];
        // Rebuilt field by field: only the allow-listed shape leaves the API.
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (!is_array($row) || !is_string($row['domain'] ?? null) || !is_string($row['status'] ?? null)) {
                continue;
            }
            $register = $row['register'] ?? null;
            $results[] = [
                'domain' => $row['domain'],
                'status' => $row['status'],
                'premium' => ($row['premium'] ?? false) === true,
                'register' => is_array($register) && is_int($register['usd'] ?? null)
                    ? ['usd' => $register['usd'], 'ngn' => is_int($register['ngn'] ?? null) ? $register['ngn'] : null]
                    : null,
            ];
        }

        return ['status' => 'done', 'results' => $results];
    }
}
