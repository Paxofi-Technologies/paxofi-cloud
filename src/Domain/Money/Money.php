<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/**
 * An amount of money: an integer of minor units (kobo, cents) and its
 * currency (PRC-001; commerce threat model CD-1). There is no float anywhere
 * in this type. Amounts are bounded to ±10^13 minor units so that no
 * operation here can overflow a 64-bit integer.
 */
final readonly class Money
{
    public const int MAX_MINOR_UNITS = 10_000_000_000_000;

    /** @throws MoneyOutOfRange */
    public function __construct(public int $minorUnits, public Currency $currency)
    {
        if ($minorUnits > self::MAX_MINOR_UNITS || $minorUnits < -self::MAX_MINOR_UNITS) {
            throw new MoneyOutOfRange('Amount is outside the supported range.');
        }
    }

    /** @throws MoneyOutOfRange|\InvalidArgumentException */
    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, Currency::fromCode($currency));
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /** @throws CurrencyMismatch|MoneyOutOfRange */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    /** @throws CurrencyMismatch|MoneyOutOfRange */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiplies by a whole factor (quantity, number of years). The factor is
     * bounded so the product is checked before it can overflow.
     *
     * @throws MoneyOutOfRange
     */
    public function times(int $factor): self
    {
        if ($factor < 0 || $factor > 1000) {
            throw new MoneyOutOfRange('Factor must be between 0 and 1000.');
        }

        return new self($this->minorUnits * $factor, $this->currency);
    }

    /**
     * The given share of this amount, rounded half-up (away from zero) to the
     * minor unit (PRC-010). 7.5% of 1,000,000 kobo is 75,000 kobo; 15% of
     * 3,333 kobo is 500 kobo.
     */
    public function percentage(BasisPoints $rate): self
    {
        $magnitude = intdiv(abs($this->minorUnits) * $rate->value + 5_000, 10_000);

        return new self($this->minorUnits < 0 ? -$magnitude : $magnitude, $this->currency);
    }

    /**
     * @param list<self> $amounts
     * @throws CurrencyMismatch|MoneyOutOfRange
     */
    public static function sum(Currency $currency, array $amounts): self
    {
        $total = self::zero($currency);
        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    /** @throws CurrencyMismatch */
    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits === $other->minorUnits;
    }

    /** @throws CurrencyMismatch */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    /** @throws CurrencyMismatch */
    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new CurrencyMismatch('Cannot combine amounts in different currencies.');
        }
    }
}
