<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Orders;

/** Display numbers: PXO-2026-000001 for orders, PXC-2026-000001 for invoices (INV-002). Never used for lookups. */
final class DocumentNumber
{
    private function __construct()
    {
    }

    public static function order(int $year, int $sequence): string
    {
        return self::format('PXO', $year, $sequence);
    }

    public static function invoice(int $year, int $sequence): string
    {
        return self::format('PXC', $year, $sequence);
    }

    private static function format(string $prefix, int $year, int $sequence): string
    {
        if ($year < 2000 || $year > 9999 || $sequence < 1) {
            throw new \InvalidArgumentException('Year and sequence out of range.');
        }

        return sprintf('%s-%04d-%06d', $prefix, $year, $sequence);
    }
}
