<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\ProviderSyncRequests;

final class InMemoryProviderSyncRequests implements ProviderSyncRequests
{
    /** @var list<array{provider: string, staff: string, at: \DateTimeImmutable, picked: ?\DateTimeImmutable}> */
    public array $requests = [];

    public function add(string $provider, string $staffId, \DateTimeImmutable $at): void
    {
        $this->requests[] = ['provider' => $provider, 'staff' => $staffId, 'at' => $at, 'picked' => null];
    }

    public function lastRequestedAt(string $provider): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->requests as $request) {
            if ($request['provider'] === $provider && ($last === null || $request['at'] > $last)) {
                $last = $request['at'];
            }
        }

        return $last;
    }

    public function pendingSince(string $provider): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($this->requests as $request) {
            if ($request['provider'] === $provider && $request['picked'] === null && ($first === null || $request['at'] < $first)) {
                $first = $request['at'];
            }
        }

        return $first;
    }

    public function claimAll(string $provider, \DateTimeImmutable $at): int
    {
        $claimed = 0;
        foreach ($this->requests as $i => $request) {
            if ($request['provider'] === $provider && $request['picked'] === null) {
                $this->requests[$i]['picked'] = $at;
                $claimed++;
            }
        }

        return $claimed;
    }
}
