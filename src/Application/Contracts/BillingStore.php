<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Billing\BillingAccount;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Domain\Money\Currency;

/** Per-account billing details (commerce threat model CD-21). */
interface BillingStore
{
    public function find(string $accountId): ?BillingAccount;

    /** Inserts or updates the account's row; the currency is ignored once locked. */
    public function save(string $accountId, BillingDetails $details, Currency $currency, \DateTimeImmutable $at): void;

    public function isOwner(string $identityId, string $accountId): bool;
}
