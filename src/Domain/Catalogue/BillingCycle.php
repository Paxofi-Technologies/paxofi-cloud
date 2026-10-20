<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
    case Biennial = 'biennial';
    case Triennial = 'triennial';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Annual => 12,
            self::Biennial => 24,
            self::Triennial => 36,
        };
    }
}
