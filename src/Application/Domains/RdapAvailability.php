<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\DomainLookup;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\RdapDirectory;

/**
 * The RDAP pre-check for one name (DS-2, DS-3, DS-6, DS-7), shared by the
 * web search (for the exact name) and the provider worker (for the rest).
 * Answers are cached for 10 minutes (1 minute when unknown) under the
 * SHA-256 of the name, so both sides reuse each other's work. Each RDAP
 * server gets at most 30 lookups per 10 seconds across all users.
 */
final readonly class RdapAvailability
{
    public const int SERVER_PER_10_SECONDS = 30;

    public function __construct(
        private RdapDirectory $directory,
        private DomainLookup $lookup,
        private RedisClient $redis,
        private RateLimiter $limiter,
    ) {
    }

    /** @return 'available'|'taken'|'unknown'|null the cached answer, null when there is none */
    public function cached(string $domain): ?string
    {
        $cached = $this->redis->get(self::key($domain));

        return in_array($cached, ['available', 'taken', 'unknown'], true) ? $cached : null;
    }

    /** @return 'available'|'taken'|'unknown' */
    public function check(string $domain): string
    {
        $cached = $this->cached($domain);
        if ($cached !== null) {
            return $cached;
        }
        $tld = explode('.', $domain, 2)[1] ?? '';
        $server = $tld === '' ? null : $this->directory->serverFor(WhoisServerDirectory::zone($tld));
        if ($server === null) {
            return 'unknown';
        }
        if ($this->limiter->hit('domain_search:server:' . hash('sha256', $server), self::SERVER_PER_10_SECONDS, 10) > 0) {
            return 'unknown';
        }
        $status = $this->lookup->check($domain, $server);
        $this->redis->setEx(self::key($domain), $status === 'unknown' ? 60 : 600, $status);

        return $status;
    }

    private static function key(string $domain): string
    {
        return 'pc:domain_search:' . hash('sha256', $domain);
    }
}
