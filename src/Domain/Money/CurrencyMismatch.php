<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/** Amounts in different currencies were combined (threat model C-02). */
final class CurrencyMismatch extends \DomainException
{
}
