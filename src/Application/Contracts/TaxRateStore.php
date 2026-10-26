<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Billing\TaxRate;

/** The append-only tax table (commerce threat model CD-22, CD-28). */
interface TaxRateStore
{
    public const int HISTORY_CAP = 500;

    /** The country's row in force at $at, else the ZZ fallback row in force. */
    public function inForce(string $country, \DateTimeImmutable $at): TaxRate;

    /**
     * Adds a row; rows are never updated or deleted (CD-28).
     *
     * @throws DuplicateTaxRate when the country already has a row starting at $from
     */
    public function add(TaxRate $rate, \DateTimeImmutable $from, string $createdBy): void;

    /**
     * Every row, newest start first, at most HISTORY_CAP.
     *
     * @return list<array{country: string, tax_name: string, rate_bp: int, effective_from: \DateTimeImmutable, created_by: string}>
     */
    public function history(): array;
}
