<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;
use PaxofiCloud\Domain\Registrar\CostAmount;

/**
 * Reads a DomainNameAPI `domains/bulk-search` answer (DS-13). The field names
 * come from the official client (domainName, status, isPremium, price,
 * currency); the shape is read defensively: the list under `infos` (what the
 * official client reads), a bare list, or a list under data/items/result. Only domains we asked about are kept, a status we do not
 * recognise is `unknown` (never available), and a premium price is kept only
 * in a currency we can price, as exact decimal text (no floats).
 */
final class DomainNameApiAvailabilityParser
{
    private const array AVAILABLE = ['available', 'free', '1', 'true'];
    private const array TAKEN = ['notavailable', 'not_available', 'unavailable', 'taken', 'registered', 'exists', 'inuse'];
    private const array RESERVED = ['reserved', 'blocked', 'restricted', 'premiumreserved', 'notallowed'];

    /**
     * @param array<array-key, mixed> $body
     * @param list<string> $asked
     * @return array<string, AvailabilityAnswer>
     */
    public static function parse(array $body, array $asked): array
    {
        $items = array_is_list($body) ? $body : null;
        foreach (['infos', 'data', 'items', 'result'] as $key) {
            if ($items === null && is_array($body[$key] ?? null)) {
                $items = $body[$key];
            }
        }
        $answers = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $domain = $item['domainName'] ?? $item['domain'] ?? null;
            if (!is_string($domain)) {
                continue;
            }
            $domain = strtolower($domain);
            if (!in_array($domain, $asked, true)) {
                continue;
            }
            $answers[$domain] = self::answer($item);
        }

        return $answers;
    }

    /** @param array<array-key, mixed> $item */
    private static function answer(array $item): AvailabilityAnswer
    {
        $raw = $item['status'] ?? null;
        // The official client also treats 1 / true as available.
        $raw = match (true) {
            $raw === true, $raw === 1 => '1',
            default => $raw,
        };
        $status = is_string($raw) ? str_replace([' ', '-'], '', strtolower($raw)) : '';
        $mapped = match (true) {
            in_array($status, self::AVAILABLE, true) => 'available',
            in_array($status, self::RESERVED, true) => 'unavailable',
            in_array($status, self::TAKEN, true) => 'taken',
            default => 'unknown',
        };
        $premium = ($item['isPremium'] ?? false) === true || ($item['isPremium'] ?? null) === 'true';
        $cost = null;
        $price = $item['price'] ?? null;
        $currency = is_string($item['currency'] ?? null) ? CostCurrency::tryFrom(strtoupper($item['currency'])) : null;
        if ($premium && $currency !== null && is_string($price) && preg_match('/^\d{1,9}(\.\d{1,8})?$/', $price) === 1) {
            try {
                $cost = CostAmount::fromDecimalText($currency, $price);
            } catch (\InvalidArgumentException) {
                $cost = null;
            }
        }

        return new AvailabilityAnswer($mapped, $premium, $cost);
    }
}
