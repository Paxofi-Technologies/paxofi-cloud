<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Database;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\ExclusiveLock;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL named lock (GET_LOCK with no wait). It belongs to the database
 * session, so it is released automatically if the worker dies mid-run.
 */
final readonly class MysqlNamedLock implements ExclusiveLock
{
    public function __construct(private Repository $repository)
    {
    }

    public function acquire(string $name): bool
    {
        self::check($name);
        $rows = $this->repository->fetchAll('SELECT GET_LOCK(:name, 0) AS acquired', ['name' => $name]);

        return isset($rows[0]) && ($rows[0]['acquired'] ?? null) !== null && Row::int($rows[0], 'acquired') === 1;
    }

    public function release(string $name): void
    {
        self::check($name);
        $this->repository->fetchAll('SELECT RELEASE_LOCK(:name) AS released', ['name' => $name]);
    }

    private static function check(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9:_-]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Lock names are short lower-case codes.');
        }
    }
}
