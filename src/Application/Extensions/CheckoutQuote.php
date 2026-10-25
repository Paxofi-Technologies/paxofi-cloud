<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Registrar\CostAmount;

/** A sellable extension's prices plus the cost basis an order line locks (DX-6). Internal: never in a customer payload (CD-5). */
final readonly class CheckoutQuote
{
    public function __construct(
        public ExtensionPrices $prices,
        public string $registrar,
        public CostAmount $registerCost,
        public string $rateSetId,
    ) {
    }

    /** One-year registration price in the billing currency, or null when not sold in it. */
    public function register(Currency $currency): ?int
    {
        return $currency === Currency::NGN ? $this->prices->ngn['register'] : $this->prices->usd['register'];
    }

    public function renew(Currency $currency): ?int
    {
        return $currency === Currency::NGN ? $this->prices->ngn['renew'] : $this->prices->usd['renew'];
    }
}
