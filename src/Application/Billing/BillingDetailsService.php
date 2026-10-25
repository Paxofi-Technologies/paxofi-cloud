<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\BillingStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Domain\Billing\TaxRate;

/**
 * Reads and saves the session account's billing details (commerce threat
 * model CD-21, C-38…C-40). The account comes only from the session; only the
 * account owner may save. The billing currency follows the country until the
 * first invoice locks it.
 */
final readonly class BillingDetailsService
{
    public const int SAVES_PER_HOUR = 20;

    public function __construct(
        private BillingStore $store,
        private TaxRateStore $taxes,
        private RateLimiter $limiter,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function find(string $accountId): ?BillingAccount
    {
        return $this->store->find($accountId);
    }

    public function taxFor(BillingAccount $billing): TaxRate
    {
        return $this->taxes->inForce($billing->details->country, $this->clock->now());
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws BillingRefused|InvalidBillingDetails
     */
    public function save(string $identityId, string $accountId, array $input, ClientContext $client): BillingAccount
    {
        if (!$this->store->isOwner($identityId, $accountId)) {
            throw new BillingRefused(BillingRefused::NOT_OWNER);
        }
        $retry = $this->limiter->hit('billing:save:' . hash('sha256', $accountId), self::SAVES_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new BillingRefused(BillingRefused::TOO_MANY, $retry);
        }
        $details = BillingDetails::fromInput($input);
        $before = $this->store->find($accountId);
        // Locked after the first invoice (PRC-003): a later country change keeps the currency.
        $currency = $before !== null && $before->currencyLocked() ? $before->currency : $details->defaultCurrency();
        $this->store->save($accountId, $details, $currency, $this->clock->now());
        $changed = $details->changedFields($before?->details);
        // Which kinds of field changed, never their values (C-38, T-50 rules for audit details).
        $this->audit->record(AuditEventType::BILLING_DETAILS_SAVED, AuditEvent::SUCCESS, $client, $identityId, $accountId, [
            'changed' => count($changed),
            'name_changed' => in_array('full_name', $changed, true) || in_array('company', $changed, true),
            'address_changed' => array_intersect(['address_line1', 'address_line2', 'city', 'region', 'postcode'], $changed) !== [],
            'country_changed' => in_array('country', $changed, true),
            'tin_changed' => in_array('tin', $changed, true),
            'currency' => strtolower($currency->value),
        ]);

        return $this->store->find($accountId) ?? new BillingAccount($details, $currency, null);
    }
}
