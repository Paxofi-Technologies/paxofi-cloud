<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DuplicateTaxRate;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;

/** The CEO-approved launch rows (CD-22): NG VAT 7.5%, US 0%, everyone else 0%; rows are only added (CD-28). */
final class InMemoryTaxRateStore implements TaxRateStore
{
    /** @var list<array{country: string, tax_name: string, rate_bp: int, effective_from: \DateTimeImmutable, created_by: string}> */
    public array $rows = [];

    public function __construct()
    {
        $from = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        foreach ([['NG', 'VAT', 750], ['US', 'Sales tax', 0], ['ZZ', 'Tax', 0]] as [$country, $name, $rate]) {
            $this->rows[] = ['country' => $country, 'tax_name' => $name, 'rate_bp' => $rate, 'effective_from' => $from, 'created_by' => 'migration'];
        }
    }

    public function inForce(string $country, \DateTimeImmutable $at): TaxRate
    {
        foreach ([$country, TaxRate::FALLBACK_COUNTRY] as $code) {
            $best = null;
            foreach ($this->rows as $row) {
                if ($row['country'] === $code && $row['effective_from'] <= $at && ($best === null || $row['effective_from'] >= $best['effective_from'])) {
                    $best = $row;
                }
            }
            if ($best !== null) {
                return new TaxRate($best['country'], $best['tax_name'], new BasisPoints($best['rate_bp']));
            }
        }

        throw new \RuntimeException('Tax table has no fallback row.');
    }

    public function add(TaxRate $rate, \DateTimeImmutable $from, string $createdBy): void
    {
        foreach ($this->rows as $row) {
            if ($row['country'] === $rate->country && $row['effective_from'] == $from) {
                throw new DuplicateTaxRate('A tax row already starts then.');
            }
        }
        $this->rows[] = ['country' => $rate->country, 'tax_name' => $rate->name, 'rate_bp' => $rate->rate->value, 'effective_from' => $from, 'created_by' => $createdBy];
    }

    public function history(): array
    {
        $rows = $this->rows;
        usort($rows, static fn (array $a, array $b): int => $b['effective_from'] <=> $a['effective_from']);

        return array_slice($rows, 0, self::HISTORY_CAP);
    }
}
