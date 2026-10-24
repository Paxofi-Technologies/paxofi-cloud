<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * One field path the registrar sent in a price list, as seen across all its
 * items (DR-16): how many items had it, which kinds of value appeared and,
 * when every value was a small whole number, the range. Never a text value.
 */
final readonly class FeedField
{
    public const array KINDS = ['text', 'object', 'list', 'bool', 'null'];

    /** @param list<string> $kinds sorted, from KINDS */
    public function __construct(
        public string $path,
        public int $items,
        public array $kinds,
        public ?int $min,
        public ?int $max,
    ) {
        if (preg_match('/^[A-Za-z(][A-Za-z0-9_.\[\]()]{0,159}$/', $path) !== 1) {
            throw new \InvalidArgumentException('A field path is a short, plain name.');
        }
        if ($items < 1 || array_diff($kinds, self::KINDS) !== [] || ($min === null) !== ($max === null) || ($min !== null && ($min < 0 || $max < $min || $max > 99999))) {
            throw new \InvalidArgumentException('Field summary out of range.');
        }
    }
}
