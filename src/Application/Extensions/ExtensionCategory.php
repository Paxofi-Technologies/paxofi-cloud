<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

/**
 * Categories for the public price list tabs (DS-12). Staff can set one per
 * extension; until they do, a short list of common extensions is pre-sorted,
 * other country codes (two-letter last label, so also com.ng) are
 * Geographic, and every other extension is Other. "Popular" is not a category: it is the featured
 * list.
 */
final class ExtensionCategory
{
    public const array ALL = [
        'business', 'technology', 'geographic', 'arts', 'sports', 'services', 'finance',
        'education', 'food', 'leisure', 'shopping', 'real_estate', 'novelty', 'other',
    ];

    private const array SUGGESTED = [
        'business' => ['com', 'net', 'org', 'biz', 'co', 'company', 'business', 'inc', 'ltd', 'llc', 'agency', 'consulting', 'ventures', 'enterprises', 'industries', 'management', 'solutions', 'group', 'partners', 'holdings', 'international', 'global', 'pro', 'info'],
        'technology' => ['io', 'ai', 'dev', 'app', 'tech', 'digital', 'cloud', 'software', 'systems', 'network', 'computer', 'data', 'codes', 'host', 'hosting', 'website', 'online', 'site', 'web', 'email', 'technology', 'tools', 'download', 'mobi'],
        'arts' => ['art', 'music', 'photo', 'photography', 'photos', 'film', 'movie', 'studio', 'design', 'gallery', 'band', 'media', 'video', 'tv', 'audio', 'graphics', 'theater', 'dance', 'blog', 'news', 'press'],
        'sports' => ['football', 'soccer', 'golf', 'bike', 'fitness', 'run', 'tennis', 'team', 'sport', 'hockey', 'rugby', 'yoga', 'racing', 'basketball', 'bet', 'casino', 'poker'],
        'services' => ['services', 'repair', 'cleaning', 'plumbing', 'builders', 'construction', 'contractors', 'engineering', 'legal', 'lawyer', 'attorney', 'care', 'health', 'clinic', 'dental', 'doctor', 'hospital', 'support', 'delivery', 'express', 'tours', 'events'],
        'finance' => ['money', 'finance', 'bank', 'capital', 'cash', 'credit', 'fund', 'investments', 'financial', 'insurance', 'loans', 'tax', 'exchange', 'trading', 'accountant', 'accountants', 'claims', 'mortgage'],
        'education' => ['edu', 'academy', 'school', 'university', 'college', 'courses', 'education', 'training', 'institute', 'degree', 'mba', 'study'],
        'food' => ['restaurant', 'cafe', 'pizza', 'kitchen', 'food', 'recipes', 'menu', 'bar', 'pub', 'wine', 'beer', 'catering', 'coffee', 'organic', 'farm'],
        'leisure' => ['travel', 'holiday', 'vacations', 'fun', 'games', 'club', 'party', 'live', 'life', 'love', 'family', 'social', 'dating', 'pet', 'pets', 'garden'],
        'shopping' => ['shop', 'store', 'buy', 'sale', 'deals', 'boutique', 'fashion', 'clothing', 'shoes', 'market', 'shopping', 'jewelry', 'gifts', 'auction', 'discount'],
        'real_estate' => ['realestate', 'properties', 'property', 'homes', 'house', 'land', 'apartments', 'rent', 'estate', 'realty', 'villas', 'condos'],
        'novelty' => ['xyz', 'lol', 'wtf', 'ninja', 'rocks', 'cool', 'guru', 'wow', 'top', 'vip', 'fyi', 'icu', 'click', 'link', 'zone', 'world', 'today', 'space', 'one'],
    ];

    /** The category to show: the staff choice, else a suggestion from the name. */
    public static function of(string $tld, ?string $chosen): string
    {
        if ($chosen !== null) {
            return $chosen;
        }
        // A suggestion wins for the few country codes sold worldwide as words (.io, .ai, .co, .tv).
        foreach (self::SUGGESTED as $category => $names) {
            if (in_array($tld, $names, true)) {
                return $category;
            }
        }
        $parts = explode('.', $tld);

        return strlen((string) end($parts)) === 2 ? 'geographic' : 'other';
    }
}
