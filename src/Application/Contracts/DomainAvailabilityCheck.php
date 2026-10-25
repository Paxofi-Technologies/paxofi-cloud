<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Registrar\AvailabilityAnswer;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;

/**
 * Asks a registrar whether names are available and what it would charge
 * (THREAT-MODEL-DOMAIN-PRICING.md SP-3). Read only: it registers nothing.
 * Called only by the provider worker, which holds the credential.
 */
interface DomainAvailabilityCheck
{
    public const int MAX_NAMES = 20;

    /** The provider name used in audit events, e.g. "spaceship". */
    public function provider(): string;

    /**
     * @param list<string> $names 1 to MAX_NAMES different lower-case ASCII domain names
     * @return list<AvailabilityAnswer> answers for requested names only, at most one each
     * @throws RegistrarUnavailable when the whole call fails
     * @throws \InvalidArgumentException for an empty, oversized or malformed list
     */
    public function check(array $names): array;
}
