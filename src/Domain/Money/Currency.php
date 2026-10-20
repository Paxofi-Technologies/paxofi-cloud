<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/** ISO 4217 currencies PaxofiCloud sells in (PRC-001, BR-01). Both use 2 minor-unit digits. */
enum Currency: string
{
    case NGN = 'NGN';
    case USD = 'USD';

    /** @throws \InvalidArgumentException for any other code */
    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? throw new \InvalidArgumentException('Unsupported currency.');
    }
}
