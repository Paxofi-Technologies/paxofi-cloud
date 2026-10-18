<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\KnownDevices;

final class InMemoryKnownDevices implements KnownDevices
{
    /** @var array<string, string> "identity|hash" => label */
    public array $devices = [];

    public function remember(string $identityId, string $deviceHash, string $label, \DateTimeImmutable $now): bool
    {
        $key = $identityId . '|' . $deviceHash;
        $new = !isset($this->devices[$key]);
        $this->devices[$key] = $label;

        return $new;
    }
}
