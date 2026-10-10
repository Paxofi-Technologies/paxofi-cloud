<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Persistence;

use UnexpectedValueException;

/** Typed access to PDO result rows; a wrong type is a bug, so it throws. */
final class Row
{
    /** @param array<string, mixed> $row */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException(sprintf('Column "%s" is not a string.', $column));
    }

    /** @param array<string, mixed> $row */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('Column "%s" is not an integer.', $column));
    }
}
