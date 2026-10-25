<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;

/** A registrar's availability check, run only by the provider worker (PC-6). */
interface AvailabilityChecker
{
    /**
     * @param list<string> $domains at most 6 full domain names
     * @return array<string, AvailabilityAnswer> by domain; a domain the registrar did not answer is absent
     * @throws \PaxofiCloud\Application\Registrar\RegistrarUnavailable
     * @throws \InvalidArgumentException for an empty or too long list
     */
    public function checkAvailability(array $domains): array;
}
