<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/** An amount or intermediate result left the supported range (threat model C-03). */
final class MoneyOutOfRange extends \DomainException
{
}
