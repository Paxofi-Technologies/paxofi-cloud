<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Billing;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\DuplicateTaxRate;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL tax table (migration 0023, CD-22, CD-28): newest row in force for the country, else the ZZ row; rows are only added. */
final readonly class MysqlTaxRateStore implements TaxRateStore
{
    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function inForce(string $country, \DateTimeImmutable $at): TaxRate
    {
        $time = self::utc($at);
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

    public function add(TaxRate $rate, \DateTimeImmutable $from, string $createdBy): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO tax_rates (country, tax_name, rate_bp, effective_from, created_by) VALUES (:country, :name, :rate, :from, :by)',
                ['country' => $rate->country, 'name' => $rate->name, 'rate' => $rate->rate->value, 'from' => self::utc($from), 'by' => $createdBy],
            );
        } catch (\RuntimeException $e) {
            // PCF wraps the driver error; look for the unique (country, effective_from) violation.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException && ($cause->errorInfo[1] ?? null) === 1062 && str_contains($cause->getMessage(), 'uq_tax_rates_country_from')) {
                    throw new DuplicateTaxRate('A tax row already starts then.', 0, $e);
                }
            }
            throw $e;
        }
    }

    public function history(): array
    {
        $rows = [];
        foreach ($this->repository->fetchAll(
            'SELECT country, tax_name, rate_bp, effective_from, created_by FROM tax_rates ORDER BY effective_from DESC, id DESC LIMIT ' . self::HISTORY_CAP,
        ) as $row) {
            $rows[] = [
                'country' => Row::string($row, 'country'),
                'tax_name' => Row::string($row, 'tax_name'),
                'rate_bp' => Row::int($row, 'rate_bp'),
                'effective_from' => new \DateTimeImmutable(Row::string($row, 'effective_from'), new \DateTimeZone('UTC')),
                'created_by' => Row::string($row, 'created_by'),
            ];
        }

        return $rows;
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
