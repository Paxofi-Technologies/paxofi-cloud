<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/**
 * VAT on a taxable line (PRC-008, BR-06/BR-07): the configured Nigerian rate
 * for customers billed in Nigeria, the configured other rate (default 0%,
 * "export of services") for everyone else. Computed per line, half-up.
 */
final readonly class VatPolicy
{
    public function __construct(public BasisPoints $nigeria, public BasisPoints $elsewhere)
    {
    }

    /** SRS default until the accountant confirms: 7.5% in Nigeria, 0% elsewhere. */
    public static function srsDefault(): self
    {
        return new self(new BasisPoints(750), new BasisPoints(0));
    }

    /** @throws \InvalidArgumentException for a malformed ISO 3166-1 alpha-2 code */
    public function rateFor(string $country): BasisPoints
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code.');
        }

        return $country === 'NG' ? $this->nigeria : $this->elsewhere;
    }

    /** @throws \InvalidArgumentException */
    public function vatOn(Money $taxableLine, string $country): Money
    {
        return $taxableLine->percentage($this->rateFor($country));
    }
}
