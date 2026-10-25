<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Extensions\ExtensionCategory;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;

/**
 * The public domain price list (THREAT-MODEL-DOMAIN-SEARCH.md DS-11): the
 * featured extensions as cards, and every extension we sell (optionally one
 * category tab, with counts per category, DS-12) with its
 * one-year register, transfer and renew price in USD and NGN. Selling
 * prices only (DX-13); a per-IP limit protects the origin and the response
 * is cacheable, so Cloudflare absorbs most traffic.
 */
final readonly class DomainPriceList
{
    public const int PAGE_SIZE = 25;
    public const int IP_PER_MINUTE = 120;

    public function __construct(private ExtensionQuotes $quotes, private RateLimiter $limiter)
    {
    }

    /**
     * @return array{featured: list<array<string, mixed>>, categories: array<string, int>, total: int, page: int, page_size: int, rows: list<array<string, mixed>>}
     * @throws DomainSearchRefused
     */
    public function view(string $clientIp, string $search, int $page, string $category = ''): array
    {
        $retry = $this->limiter->hit('domain_prices:ip:' . hash('sha256', $clientIp !== '' ? $clientIp : 'unknown'), self::IP_PER_MINUTE, 60);
        if ($retry > 0) {
            throw new DomainSearchRefused(DomainSearchRefused::TOO_MANY, $retry);
        }
        $needle = strtolower(ltrim(trim($search), '.'));
        if (($needle !== '' && preg_match('/^[a-z0-9.-]{1,63}$/', $needle) !== 1) || ($category !== '' && !in_array($category, ExtensionCategory::ALL, true))) {
            throw new DomainSearchRefused(DomainSearchRefused::INVALID_NAME);
        }

        $featured = [];
        foreach ($this->quotes->featured() as $tld) {
            $row = $this->row($tld);
            if ($row !== null) {
                $featured[] = $row;
            }
        }
        $rows = [];
        $counts = array_fill_keys(ExtensionCategory::ALL, 0);
        foreach ($this->quotes->extensions() as $tld) {
            if ($needle !== '' && !str_contains($tld, $needle)) {
                continue;
            }
            $row = $this->row($tld);
            if ($row === null) {
                continue;
            }
            $counts[$row['category']]++;
            if ($category === '' || $row['category'] === $category) {
                $rows[] = $row;
            }
        }
        $total = count($rows);
        $page = max(1, min($page, max(1, (int) ceil($total / self::PAGE_SIZE))));

        return [
            'featured' => $featured,
            'categories' => $counts,
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'rows' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
        ];
    }

    /** @return ?array{tld: string, featured: bool, label: string, category: string, register: ?array{usd: int, ngn: ?int}, transfer: ?array{usd: int, ngn: ?int}, renew: ?array{usd: int, ngn: ?int}} allow-listed fields only */
    private function row(string $tld): ?array
    {
        $prices = $this->quotes->quote($tld);
        if ($prices === null) {
            return null;
        }
        $pair = static fn (string $action): ?array => $prices->usd[$action] === null
            ? null
            : ['usd' => $prices->usd[$action], 'ngn' => $prices->ngn[$action]];

        return [
            'tld' => $tld,
            'featured' => in_array($tld, $this->quotes->featured(), true),
            'label' => $this->quotes->label($tld),
            'category' => $this->quotes->category($tld),
            'register' => $pair('register'),
            'transfer' => $pair('transfer'),
            'renew' => $pair('renew'),
        ];
    }
}
