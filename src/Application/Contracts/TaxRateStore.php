<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Billing\TaxRate;

/** The append-only tax table (commerce threat model CD-22). */
interface TaxRateStore
{
    /** The country's row in force at $at, else the ZZ fallback row in force. */
    public function inForce(string $country, \DateTimeImmutable $at): TaxRate;
}
