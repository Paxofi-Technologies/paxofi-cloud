<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;

/**
 * The customer domain search (THREAT-MODEL-DOMAIN-SEARCH.md DS-1 to DS-10,
 * DS-22 to DS-24): one label, checked in the requested extension and the
 * search extensions staff chose (until they do, the featured ones plus `ng`
 * and `com.ng`; at most 12 per search), each with our selling price, plus
 * up to 30 priced name ideas (DS-23) for the page to confirm; the page shows
 * at most the staff's "ideas shown" number. The first 6 names get the RDAP
 * pre-check now; the rest come from the cache or show as being checked, and
 * the page asks the provider worker to confirm everything that is not taken
 * (DS-13, DS-14). Searched names are never stored or logged; the cache key
 * is a SHA-256 of the name.
 */
final readonly class DomainSearch
{
    public const int MAX_EXTENSIONS = 12;
    public const int CHECK_NOW = 6;
    public const int MAX_IDEAS = 30;
    /** Checked with every search until staff choose the search extensions (DS-22). */
    public const array DEFAULT_SEARCH_EXTENSIONS = ['ng', 'com.ng'];
    public const int IP_PER_MINUTE = 20;
    public const int IP_PER_HOUR = 300;
    public const int SERVER_PER_10_SECONDS = RdapAvailability::SERVER_PER_10_SECONDS;
    public const string DEFAULT_TLD = 'com';
    private const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private ExtensionQuotes $quotes,
        private RdapAvailability $rdap,
        private RateLimiter $limiter,
        private ?DomainSearchSettingsStore $settings = null,
    ) {
    }

    /**
     * @return array{name: string, results: list<array{domain: string, tld: string, status: string, featured: bool, label: string,
     *     register: array{usd: int, ngn: int}, renew: ?array{usd: int, ngn: ?int}}>,
     *     ideas: list<array{domain: string, tld: string, label: string, register: array{usd: int, ngn: int}, renew: ?array{usd: int, ngn: ?int}}>,
     *     ideas_shown: int}
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
        $settings = $this->settings?->settings() ?? DomainSearchSettings::defaults();
        $searchSet = array_slice(DomainSearchAdmin::searchSet($settings, $this->quotes), 0, self::MAX_EXTENSIONS);
        $tlds = array_values(array_unique([$requested, ...$searchSet]));
        $results = [];
        foreach ($tlds as $candidate) {
            $prices = $this->quotes->quote($candidate);
            if ($prices === null) {
                continue;
            }
            $domain = $name . '.' . $candidate;
            $status = count($results) < self::CHECK_NOW ? $this->rdap->check($domain) : ($this->rdap->cached($domain) ?? 'unknown');
            $results[] = [
                'domain' => $domain,
                'tld' => $candidate,
                'status' => $status,
                'featured' => in_array($candidate, $featured, true),
                'label' => $this->quotes->label($candidate),
                'register' => ['usd' => (int) $prices->usd['register'], 'ngn' => (int) $prices->ngn['register']],
                'renew' => $prices->usd['renew'] === null ? null : ['usd' => $prices->usd['renew'], 'ngn' => $prices->ngn['renew']],
            ];
        }

        $ideas = [];
        // A few more candidates than shown: only the ones confirmed available are shown.
        $candidates = $settings->maxIdeas === 0 ? 0 : min(self::MAX_IDEAS, $settings->maxIdeas + 10);
        foreach (NameIdeas::generate($name, $requested, $this->quotes->extensions(), array_column($results, 'domain'), $candidates, $settings->prefixes, $settings->suffixes) as $idea) {
            [, $ideaTld] = explode('.', $idea, 2);
            $prices = $this->quotes->quote($ideaTld);
            if ($prices === null || $prices->usd['register'] === null || $prices->ngn['register'] === null) {
                continue;
            }
            $ideas[] = [
                'domain' => $idea,
                'tld' => $ideaTld,
                'label' => $this->quotes->label($ideaTld),
                'register' => ['usd' => $prices->usd['register'], 'ngn' => $prices->ngn['register']],
                'renew' => $prices->usd['renew'] === null ? null : ['usd' => $prices->usd['renew'], 'ngn' => $prices->ngn['renew']],
            ];
        }

        return ['name' => $name, 'results' => $results, 'ideas' => $ideas, 'ideas_shown' => $settings->maxIdeas];
    }
}
