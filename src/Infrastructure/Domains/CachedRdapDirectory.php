<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\RdapDirectory;
use PaxofiCloud\Integration\Rdap\RdapBootstrap;

/**
 * The IANA bootstrap map, cached in Redis for 24 hours (DS-2). A failed
 * refresh keeps the last good copy; a fetch is attempted at most once per
 * 10 minutes so a bootstrap outage cannot multiply calls.
 */
final class CachedRdapDirectory implements RdapDirectory
{
    private const string FRESH = 'pc:rdap:bootstrap:fresh';
    private const string LAST_GOOD = 'pc:rdap:bootstrap:last';
    private const string ATTEMPT = 'pc:rdap:bootstrap:attempt';

    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct(private readonly RedisClient $redis, private readonly RdapBootstrap $bootstrap)
    {
    }

    public function serverFor(string $tld): ?string
    {
        $this->map ??= $this->load();
        $server = $this->map[$tld] ?? null;

        return is_string($server) ? $server : null;
    }

    /** @return array<string, string> */
    private function load(): array
    {
        if ($this->redis->exists(self::FRESH)) {
            return self::decode($this->redis->get(self::LAST_GOOD));
        }
        if ($this->redis->setIfAbsentEx(self::ATTEMPT, 600, '1')) {
            $fresh = $this->bootstrap->fetch();
            if ($fresh !== null) {
                $this->redis->set(self::LAST_GOOD, json_encode($fresh, JSON_THROW_ON_ERROR));
                $this->redis->setEx(self::FRESH, 86_400, '1');

                return $fresh;
            }
        }

        return self::decode($this->redis->get(self::LAST_GOOD));
    }

    /** @return array<string, string> */
    private static function decode(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        try {
            $map = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($map) ? array_filter($map, static fn (mixed $v, mixed $k): bool => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH) : [];
    }
}
