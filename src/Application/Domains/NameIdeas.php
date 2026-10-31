<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/**
 * Built-in name ideas (THREAT-MODEL-DOMAIN-SEARCH.md DS-23, DS-T21, DS-T22):
 * generated here, with no outside service and nothing about the customer
 * sent anywhere. Ideas are (a) the same label in other extensions we sell,
 * popular ones first, then (b) the label with a short prefix or suffix, in
 * `.com` and the requested extension. Every idea passes the DS-4 label rule
 * and is in an extension on sale. The page shows only the ones confirmed
 * available.
 */
final class NameIdeas
{
    /** Extensions tried first for the same label, when on sale. */
    public const array POPULAR = ['com', 'ng', 'com.ng', 'net', 'org', 'online', 'store', 'shop', 'africa', 'co', 'io', 'tech', 'site', 'xyz', 'org.ng', 'name.ng'];
    public const array PREFIXES = ['get', 'my', 'try', 'the'];
    public const array SUFFIXES = ['hq', 'app', 'online', 'hub', 'ng', 'africa'];
    private const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * @param list<string> $onSale every extension we sell
     * @param list<string> $exclude full names already shown
     * @param list<string>|null $prefixes staff words (DS-23), default PREFIXES
     * @param list<string>|null $suffixes staff words (DS-23), default SUFFIXES
     * @return list<string> full domain names, best first, at most $max
     */
    public static function generate(string $label, string $requestedTld, array $onSale, array $exclude, int $max, ?array $prefixes = null, ?array $suffixes = null): array
    {
        if ($max <= 0 || preg_match(self::LABEL, $label) !== 1) {
            return [];
        }
        $sale = array_flip($onSale);
        $seen = array_flip($exclude);
        $ideas = [];
        $add = static function (string $candidateLabel, string $tld) use (&$ideas, &$seen, $sale, $max): void {
            if (count($ideas) >= $max || !isset($sale[$tld]) || strlen($candidateLabel) > 63 || preg_match(self::LABEL, $candidateLabel) !== 1) {
                return;
            }
            $domain = $candidateLabel . '.' . $tld;
            if (isset($seen[$domain])) {
                return;
            }
            $seen[$domain] = true;
            $ideas[] = $domain;
        };

        // (a) Same label, other extensions: about half the ideas.
        $half = intdiv($max + 1, 2);
        foreach (self::POPULAR as $tld) {
            if (count($ideas) >= $half) {
                break;
            }
            $add($label, $tld);
        }

        // (b) Prefixes and suffixes, in .com and the requested extension, alternating so both kinds show.
        $words = [];
        $pre = self::clean($prefixes ?? self::PREFIXES);
        $suf = self::clean($suffixes ?? self::SUFFIXES);
        for ($i = 0, $n = max(count($pre), count($suf)); $i < $n; $i++) {
            if (isset($pre[$i])) {
                $words[] = $pre[$i] . $label;
            }
            if (isset($suf[$i]) && !str_ends_with($label, $suf[$i])) {
                $words[] = $label . $suf[$i];
            }
        }
        foreach ($words as $word) {
            $add($word, 'com');
            $add($word, $requestedTld);
        }

        // Fill any room left with the same label in the rest of the popular list.
        foreach (self::POPULAR as $tld) {
            $add($label, $tld);
        }

        return $ideas;
    }

    /**
     * Staff words: 2-12 characters, `a-z 0-9` only (DS-T22).
     *
     * @param list<string> $words
     * @return list<string>
     */
    public static function clean(array $words): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $w): string => strtolower(trim($w)), $words),
            static fn (string $w): bool => preg_match('/^[a-z0-9]{2,12}$/', $w) === 1,
        )));
    }
}
