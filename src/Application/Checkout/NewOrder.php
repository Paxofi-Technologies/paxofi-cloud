<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Checkout;

use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\Currency;

/** Everything one checkout transaction writes (CD-24): order, lines, invoice and its billing snapshot. */
final readonly class NewOrder
{
    /** @param list<NewOrderLine> $lines */
    public function __construct(
        public string $orderId,
        public string $invoiceId,
        public string $accountId,
        public string $identityId,
        public Currency $currency,
        public TaxRate $tax,
        public BillingDetails $billTo,
        public ?string $visitorCountry,
        public bool $flaggedForReview,
        public string $termsVersion,
        public string $idempotencyKey,
        public string $requestHash,
        public \DateTimeImmutable $placedAt,
        public \DateTimeImmutable $expiresAt,
        public array $lines,
    ) {
        if ($lines === [] || count($lines) > 20) {
            throw new \InvalidArgumentException('An order has 1 to 20 lines.');
        }
    }

    public function subtotalMinor(): int
    {
        return array_sum(array_map(static fn (NewOrderLine $l): int => $l->priceMinor, $this->lines));
    }

    public function taxMinor(): int
    {
        return array_sum(array_map(static fn (NewOrderLine $l): int => $l->taxMinor, $this->lines));
    }
}
