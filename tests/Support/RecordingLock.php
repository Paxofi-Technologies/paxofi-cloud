<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\ExclusiveLock;

final class RecordingLock implements ExclusiveLock
{
    public bool $busy = false;
    /** @var list<string> */
    public array $held = [];
    /** @var list<string> */
    public array $log = [];

    public function acquire(string $name): bool
    {
        $this->log[] = 'acquire ' . $name;
        if ($this->busy || in_array($name, $this->held, true)) {
            return false;
        }
        $this->held[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        $this->log[] = 'release ' . $name;
        $this->held = array_values(array_diff($this->held, [$name]));
    }
}
