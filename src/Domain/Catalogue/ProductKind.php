<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

enum ProductKind: string
{
    case Domain = 'domain';
    case Hosting = 'hosting';
    case Vps = 'vps';

    /**
     * Billing cycles each kind may be sold on (BR-09). Domains are priced per
     * year; the number of years (1–10) is chosen in the cart.
     *
     * @return list<BillingCycle>
     */
    public function allowedCycles(): array
    {
        return match ($this) {
            self::Domain => [BillingCycle::Annual],
            self::Hosting => [BillingCycle::Monthly, BillingCycle::Annual, BillingCycle::Biennial, BillingCycle::Triennial],
            self::Vps => [BillingCycle::Monthly, BillingCycle::Annual],
        };
    }
}
