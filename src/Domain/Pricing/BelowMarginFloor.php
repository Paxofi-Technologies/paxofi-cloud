<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/** A derived price would fall below cost plus the minimum margin, so it is never published (CD-14). */
final class BelowMarginFloor extends \DomainException
{
}
