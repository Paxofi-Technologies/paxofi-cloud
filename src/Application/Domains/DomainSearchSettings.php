<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/**
 * Staff settings for the customer search (DS-22, DS-23): the extensions
 * checked with every search (in order, at most 20; only those on sale are
 * used, and at most 12 per search), how many name ideas to show (0 turns
 * ideas off, at most 30) and the idea words (at most 12 of each, 2-12
 * characters, `a-z 0-9`).
 */
final readonly class DomainSearchSettings
{
    public const int MAX_SEARCH_TLDS = 20;
    public const int MAX_IDEAS = 30;
    public const int MAX_WORDS = 12;
    public const int DEFAULT_IDEAS = 20;
    private const string TLD = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$/';

    /**
     * @param list<string>|null $searchTlds null = not chosen yet: the featured extensions plus ng and com.ng
     * @param list<string> $prefixes
     * @param list<string> $suffixes
     */
    public function __construct(
        public ?array $searchTlds,
        public int $maxIdeas,
        public array $prefixes,
        public array $suffixes,
    ) {
        if ($searchTlds !== null) {
            if (count($searchTlds) > self::MAX_SEARCH_TLDS || count(array_unique($searchTlds)) !== count($searchTlds)) {
                throw new \InvalidArgumentException('Too many or repeated search extensions.');
            }
            foreach ($searchTlds as $tld) {
                if (preg_match(self::TLD, $tld) !== 1) {
                    throw new \InvalidArgumentException('Invalid extension.');
                }
            }
        }
        if ($maxIdeas < 0 || $maxIdeas > self::MAX_IDEAS) {
            throw new \InvalidArgumentException('Ideas out of range.');
        }
        foreach ([$prefixes, $suffixes] as $words) {
            if (count($words) > self::MAX_WORDS || NameIdeas::clean($words) !== $words) {
                throw new \InvalidArgumentException('Invalid idea words.');
            }
        }
    }

    public static function defaults(): self
    {
        return new self(null, self::DEFAULT_IDEAS, NameIdeas::PREFIXES, NameIdeas::SUFFIXES);
    }
}
