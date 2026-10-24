<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * Collects the field paths of a registrar price list (DR-16) so staff can see
 * what the registrar actually sends, for example whether grace or redemption
 * periods are there, before any parser change relies on them.
 *
 * Paths: top-level keys, `prices[].<type>` and `prices[].<type>.<key>` (a
 * per-period list counts as its entries). Keys outside a strict pattern count
 * as `(other)`. Values are never kept: only their kind and, when every value
 * on a path is a 1-5 digit whole number, the range.
 */
final class FeedFieldInventory
{
    public const int MAX_PATHS = 200;
    public const string OTHER = '(other)';

    /** @var array<string, array{items: int, kinds: array<string, true>, min: ?int, max: ?int, numeric: bool}> */
    private array $paths = [];

    /** Records one raw item (as decoded by ExactJson, so numbers are text). */
    public function add(mixed $item): void
    {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            return;
        }
        $seen = [];
        foreach ($item as $key => $value) {
            $name = self::key($key);
            $this->note($name, $value, $seen);
            if ($name === 'prices' && is_array($value) && array_is_list($value)) {
                foreach ($value as $byType) {
                    if (!is_array($byType) || array_is_list($byType)) {
                        continue;
                    }
                    foreach ($byType as $type => $prices) {
                        $typePath = 'prices[].' . self::key($type);
                        $this->note($typePath, $prices, $seen);
                        $entries = is_array($prices) && $prices !== [] && array_is_list($prices) ? $prices : [$prices];
                        foreach ($entries as $entry) {
                            if (is_array($entry) && !array_is_list($entry)) {
                                foreach ($entry as $field => $fieldValue) {
                                    $this->note($typePath . '.' . self::key($field), $fieldValue, $seen);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** @return list<FeedField> ordered by path */
    public function fields(): array
    {
        ksort($this->paths, SORT_STRING);
        $fields = [];
        foreach ($this->paths as $path => $p) {
            $kinds = array_keys($p['kinds']);
            sort($kinds, SORT_STRING);
            $numeric = $p['numeric'] && $p['min'] !== null;
            $fields[] = new FeedField($path, $p['items'], $kinds, $numeric ? $p['min'] : null, $numeric ? $p['max'] : null);
        }

        return $fields;
    }

    /** @param array<string, true> $seen paths already counted for this item */
    private function note(string $path, mixed $value, array &$seen): void
    {
        if (!isset($this->paths[$path])) {
            if (count($this->paths) >= self::MAX_PATHS) {
                return;
            }
            $this->paths[$path] = ['items' => 0, 'kinds' => [], 'min' => null, 'max' => null, 'numeric' => true];
        }
        $p = &$this->paths[$path];
        if (!isset($seen[$path])) {
            $p['items']++;
            $seen[$path] = true;
        }
        $kind = match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_array($value) => $value === [] || array_is_list($value) ? 'list' : 'object',
            default => 'text',
        };
        $p['kinds'][$kind] = true;
        if (is_string($value) && preg_match('/^[0-9]{1,5}$/', $value) === 1) {
            $n = (int) $value;
            $p['min'] = $p['min'] === null ? $n : min($p['min'], $n);
            $p['max'] = $p['max'] === null ? $n : max($p['max'], $n);
        } else {
            $p['numeric'] = false;
        }
    }

    private static function key(int|string $key): string
    {
        return is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,47}$/', $key) === 1 ? $key : self::OTHER;
    }
}
