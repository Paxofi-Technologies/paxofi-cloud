<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Devices each identity has signed in from (threat model T-55, §6.15). */
interface KnownDevices
{
    /** Records the sign-in; true when this device hash was not yet known for the identity. */
    public function remember(string $identityId, string $deviceHash, string $label, \DateTimeImmutable $now): bool;
}
