<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Billing;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL tax table (migration 0023, CD-22): newest row in force for the country, else the ZZ row. */
final readonly class MysqlTaxRateStore implements TaxRateStore
{
    public function __construct(private Repository $repository)
    {
    }

    public function inForce(string $country, \DateTimeImmutable $at): TaxRate
    {
        $time = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        foreach ([$country, TaxRate::FALLBACK_COUNTRY] as $code) {
            $row = $this->repository->fetchAll(
                'SELECT country, tax_name, rate_bp FROM tax_rates WHERE country = :country AND effective_from <= :at ORDER BY effective_from DESC LIMIT 1',
                ['country' => $code, 'at' => $time],
            )[0] ?? null;
            if ($row !== null) {
                return new TaxRate(Row::string($row, 'country'), Row::string($row, 'tax_name'), new BasisPoints(Row::int($row, 'rate_bp')));
            }
        }

        // Fail closed: without a fallback row no tax can be computed, so checkout must stop.
        throw new \RuntimeException('Tax table has no fallback row.');
    }
}
