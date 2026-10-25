<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Cart;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;

/**
 * The signed-in customer's domain cart (THREAT-MODEL-COMMERCE.md CD-20).
 * Lines hold a domain name and nothing else: no price is stored (CD-3), and
 * every read re-quotes each line from the current price engine. The cart is
 * keyed by the session's account only; there is no cart or account input.
 */
final readonly class DomainCart
{
    public const int MAX_LINES = 20;
    public const int TTL_SECONDS = 2_592_000;
    public const int ADDS_PER_HOUR = 60;
    private const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';
    private const string EXTENSION = '/^[a-z0-9]+(\.[a-z0-9]+)*$/';

    public function __construct(private RedisClient $redis, private ExtensionQuotes $quotes, private RateLimiter $limiter)
    {
    }

    public static function key(string $accountId): string
    {
        return 'pc:cart:' . hash('sha256', $accountId);
    }

    /**
     * @return array{lines: list<array{id: string, domain: string, tld: string, years: int, available: bool, register: ?array{usd: int, ngn: ?int}}>, total: array{usd: int, ngn: ?int}}
     * @phpstan-impure reads the cart from Redis
     */
    public function view(string $accountId): array
    {
        $lines = [];
        $usd = 0;
        $ngn = 0;
        foreach ($this->lines($accountId) as $line) {
            $tld = explode('.', $line['domain'], 2)[1];
            $prices = $this->quotes->quote($tld);
            $registerUsd = $prices?->usd['register'] ?? null;
            $register = $registerUsd === null ? null : ['usd' => $registerUsd, 'ngn' => $prices?->ngn['register'] ?? null];
            if ($register !== null) {
                $usd += $register['usd'];
                $ngn = $ngn === null || $register['ngn'] === null ? null : $ngn + $register['ngn'];
            }
            $lines[] = ['id' => $line['id'], 'domain' => $line['domain'], 'tld' => $tld, 'years' => 1, 'available' => $register !== null, 'register' => $register];
        }

        return ['lines' => $lines, 'total' => ['usd' => $usd, 'ngn' => $ngn]];
    }

    /** @throws CartRefused */
    public function addDomain(string $accountId, mixed $domain): void
    {
        $retry = $this->limiter->hit('cart:add:' . hash('sha256', $accountId), self::ADDS_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new CartRefused(CartRefused::TOO_MANY, $retry);
        }
        if (!is_string($domain) || strlen($domain) > 253) {
            throw new CartRefused(CartRefused::INVALID_NAME);
        }
        $domain = strtolower(trim($domain));
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2 || preg_match(self::LABEL, $parts[0]) !== 1 || preg_match(self::EXTENSION, $parts[1]) !== 1) {
            throw new CartRefused(CartRefused::INVALID_NAME);
        }
        if (($this->quotes->quote($parts[1])?->usd['register'] ?? null) === null) {
            throw new CartRefused(CartRefused::NOT_FOR_SALE);
        }
        $lines = $this->lines($accountId);
        foreach ($lines as $line) {
            if ($line['domain'] === $domain) {
                return;
            }
        }
        if (count($lines) >= self::MAX_LINES) {
            throw new CartRefused(CartRefused::CART_FULL);
        }
        $lines[] = ['id' => bin2hex(random_bytes(8)), 'domain' => $domain];
        $this->save($accountId, $lines);
    }

    /**
     * The validated names in the cart, for checkout (CD-23).
     *
     * @return list<string>
     * @phpstan-impure
     */
    public function domains(string $accountId): array
    {
        return array_map(static fn (array $l): string => $l['domain'], $this->lines($accountId));
    }

    /**
     * Removes ordered names after a successful checkout; unknown names are ignored.
     *
     * @param list<string> $domains
     */
    public function removeDomains(string $accountId, array $domains): void
    {
        $kept = array_values(array_filter($this->lines($accountId), static fn (array $l): bool => !in_array($l['domain'], $domains, true)));
        $this->save($accountId, $kept);
    }

    /** @throws CartRefused */
    public function remove(string $accountId, mixed $lineId): void
    {
        if (!is_string($lineId) || preg_match('/^[0-9a-f]{16}$/', $lineId) !== 1) {
            throw new CartRefused(CartRefused::LINE_NOT_FOUND);
        }
        $lines = $this->lines($accountId);
        $kept = array_values(array_filter($lines, static fn (array $l): bool => !hash_equals($l['id'], $lineId)));
        if (count($kept) === count($lines)) {
            throw new CartRefused(CartRefused::LINE_NOT_FOUND);
        }
        $this->save($accountId, $kept);
    }

    /**
     * Stored lines, each re-validated on read; anything malformed is dropped.
     *
     * @return list<array{id: string, domain: string}>
     * @phpstan-impure
     */
    private function lines(string $accountId): array
    {
        $raw = $this->redis->get(self::key($accountId));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $lines = [];
        foreach (is_array($data) ? $data : [] as $line) {
            $id = is_array($line) ? ($line['id'] ?? null) : null;
            $domain = is_array($line) ? ($line['domain'] ?? null) : null;
            if (!is_string($id) || preg_match('/^[0-9a-f]{16}$/', $id) !== 1 || !is_string($domain)) {
                continue;
            }
            $parts = explode('.', $domain, 2);
            if (count($parts) !== 2 || preg_match(self::LABEL, $parts[0]) !== 1 || preg_match(self::EXTENSION, $parts[1]) !== 1) {
                continue;
            }
            $lines[] = ['id' => $id, 'domain' => $domain];
        }

        return array_slice($lines, 0, self::MAX_LINES);
    }

    /** @param list<array{id: string, domain: string}> $lines */
    private function save(string $accountId, array $lines): void
    {
        if ($lines === []) {
            $this->redis->delete(self::key($accountId));

            return;
        }
        $this->redis->setEx(self::key($accountId), self::TTL_SECONDS, json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
