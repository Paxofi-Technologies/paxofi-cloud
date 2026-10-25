<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

use PaxofiCloud\Domain\Money\Currency;

/** Saved billing details plus the account's billing currency and whether the first invoice has locked it. */
final readonly class BillingAccount
{
    public function __construct(
        public BillingDetails $details,
        public Currency $currency,
        public ?\DateTimeImmutable $currencyLockedAt,
    ) {
    }

    public function currencyLocked(): bool
    {
        return $this->currencyLockedAt !== null;
    }
}
