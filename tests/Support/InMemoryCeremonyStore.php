<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\CeremonyStore;

final class InMemoryCeremonyStore implements CeremonyStore
{
    /** @var array<string, array<string, string>> */
    public array $states = [];

    public function put(string $ceremonyHash, array $state, int $ttlSeconds): void
    {
        $this->states[$ceremonyHash] = $state;
    }

    public function take(string $ceremonyHash): ?array
    {
        $state = $this->states[$ceremonyHash] ?? null;
        unset($this->states[$ceremonyHash]);

        return $state;
    }
}
