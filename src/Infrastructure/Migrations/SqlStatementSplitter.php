<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

/**
 * Splits a migration file into single statements on top-level semicolons,
 * respecting quotes ('…', "…", `…`) and comments (--, #, block comments).
 * Comments are dropped. Stored routines with DELIMITER are not supported.
 */
final class SqlStatementSplitter
{
    /** @return list<string> */
    public function split(string $sql): array
    {
        if (preg_match('/^\s*DELIMITER\s/mi', $sql) === 1) {
            throw new MigrationError('DELIMITER is not supported in migrations.');
        }

        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
            } elseif (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new MigrationError('Unterminated block comment.');
                }
                $i = $end + 1;
                $current .= ' ';
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($quote !== null) {
            throw new MigrationError('Unterminated quoted string.');
        }
        $statements[] = $current;

        return array_values(array_filter(
            array_map(trim(...), $statements),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}
