<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

/**
 * Loads forward-only SQL migrations named NNNN_snake_case_name.sql.
 * Any other .sql file is an error rather than being silently skipped.
 */
final class MigrationLoader
{
    private const string PATTERN = '/^(\d{4,})_([a-z0-9]+(?:_[a-z0-9]+)*)\.sql$/';

    /** @return list<Migration> sorted by version */
    public function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new MigrationError(sprintf('Migrations directory not found: %s', $directory));
        }

        $migrations = [];
        $files = scandir($directory);
        foreach ($files === false ? [] : $files as $file) {
            if (!str_ends_with($file, '.sql')) {
                continue;
            }
            if (preg_match(self::PATTERN, $file, $match) !== 1) {
                throw new MigrationError(sprintf('Invalid migration file name "%s"; expected NNNN_name.sql.', $file));
            }
            $version = $match[1];
            $number = (int) $version;
            if (isset($migrations[$number])) {
                throw new MigrationError(sprintf('Duplicate migration version %s.', $version));
            }
            $sql = file_get_contents($directory . '/' . $file);
            if ($sql === false || trim($sql) === '') {
                throw new MigrationError(sprintf('Migration %s is empty or unreadable.', $file));
            }
            $migrations[$number] = new Migration($version, $match[2], $sql);
        }

        ksort($migrations);

        return array_values($migrations);
    }
}
