<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\DomainLookup;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\RdapDirectory;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;

/**
 * The customer domain search (THREAT-MODEL-DOMAIN-SEARCH.md DS-1 to DS-10):
 * one label, checked in the requested extension and the featured ones (at
 * most 6 names), each with our selling price. Availability is an RDAP
 * pre-check; the registrar confirms at checkout. Searched names are never
 * stored or logged; the cache key is a SHA-256 of the name.
 */
final readonly class DomainSearch
{
    public const int MAX_NAMES = 6;
    public const int IP_PER_MINUTE = 20;
    public const int IP_PER_HOUR = 300;
    public const int SERVER_PER_10_SECONDS = 30;
    public const string DEFAULT_TLD = 'com';
    private const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private ExtensionQuotes $quotes,
        private RdapDirectory $directory,
        private DomainLookup $lookup,
        private RedisClient $redis,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * @return array{name: string, results: list<array{domain: string, tld: string, status: string, featured: bool, label: string,
     *     register: array{usd: int, ngn: int}, renew: ?array{usd: int, ngn: ?int}}>}
     * @throws DomainSearchRefused
     */
    public function search(string $clientIp, string $name, ?string $tld): array
    {
        $ipKey = hash('sha256', $clientIp !== '' ? $clientIp : 'unknown');
        foreach ([[self::IP_PER_MINUTE, 60], [self::IP_PER_HOUR, 3600]] as [$limit, $window]) {
            $retry = $this->limiter->hit('domain_search:ip:' . $window . ':' . $ipKey, $limit, $window);
            if ($retry > 0) {
                throw new DomainSearchRefused(DomainSearchRefused::TOO_MANY, $retry);
            }
        }

        // A customer may type "example.com"; the label is what is before the first dot.
        $name = strtolower(trim($name));
        if (str_contains($name, '.') && $tld === null) {
            [$name, $tld] = explode('.', $name, 2);
        }
        if (strlen($name) > 63 || preg_match(self::LABEL, $name) !== 1) {
            throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
        }
        $requested = strtolower(ltrim(trim($tld ?? self::DEFAULT_TLD), '.'));
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$/', $requested) !== 1 || $this->quotes->quote($requested) === null) {
            throw new DomainSearchRefused(DomainSearchRefused::UNKNOWN_EXTENSION);
        }

        $featured = $this->quotes->featured();
        $tlds = array_slice(array_values(array_unique([$requested, ...$featured])), 0, self::MAX_NAMES);
        $results = [];
        foreach ($tlds as $candidate) {
            $prices = $this->quotes->quote($candidate);
            if ($prices === null) {
                continue;
            }
            $domain = $name . '.' . $candidate;
            $results[] = [
                'domain' => $domain,
                'tld' => $candidate,
                'status' => $this->status($domain, $candidate),
                'featured' => in_array($candidate, $featured, true),
                'label' => $this->quotes->label($candidate),
                'register' => ['usd' => (int) $prices->usd['register'], 'ngn' => (int) $prices->ngn['register']],
                'renew' => $prices->usd['renew'] === null ? null : ['usd' => $prices->usd['renew'], 'ngn' => $prices->ngn['renew']],
            ];
        }

        return ['name' => $name, 'results' => $results];
    }

    /** @return 'available'|'taken'|'unknown' */
    private function status(string $domain, string $tld): string
    {
        $key = 'pc:domain_search:' . hash('sha256', $domain);
        $cached = $this->redis->get($key);
        if (in_array($cached, ['available', 'taken', 'unknown'], true)) {
            return $cached;
        }
        $server = $this->directory->serverFor(self::rdapZone($tld));
        if ($server === null) {
            return 'unknown';
        }
        if ($this->limiter->hit('domain_search:server:' . hash('sha256', $server), self::SERVER_PER_10_SECONDS, 10) > 0) {
            return 'unknown';
        }
        $status = $this->lookup->check($domain, $server);
        $this->redis->setEx($key, $status === 'unknown' ? 60 : 600, $status);

        return $status;
    }

    /** IANA lists top-level zones: "com.ng" is served by the "ng" server. */
    private static function rdapZone(string $tld): string
    {
        $parts = explode('.', $tld);

        return (string) end($parts);
    }
}
