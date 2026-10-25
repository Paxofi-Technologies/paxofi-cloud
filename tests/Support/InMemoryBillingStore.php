<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Billing\BillingAccount;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Application\Contracts\BillingStore;
use PaxofiCloud\Domain\Money\Currency;

final class InMemoryBillingStore implements BillingStore
{
    /** @var array<string, BillingAccount> */
    public array $accounts = [];
    /** @var array<string, string> account => owner identity */
    public array $owners = [];

    public function find(string $accountId): ?BillingAccount
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function save(string $accountId, BillingDetails $details, Currency $currency, \DateTimeImmutable $at): void
    {
        $before = $this->accounts[$accountId] ?? null;
        $locked = $before?->currencyLockedAt;
        $this->accounts[$accountId] = new BillingAccount($details, $locked === null ? $currency : $before->currency, $locked);
    }

    public function isOwner(string $identityId, string $accountId): bool
    {
        return ($this->owners[$accountId] ?? null) === $identityId;
    }

    public function lock(string $accountId, \DateTimeImmutable $at): void
    {
        $b = $this->accounts[$accountId];
        $this->accounts[$accountId] = new BillingAccount($b->details, $b->currency, $at);
    }
}
