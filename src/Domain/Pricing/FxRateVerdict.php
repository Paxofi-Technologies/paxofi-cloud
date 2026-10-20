<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/** The outcome of checking newly fetched rates against the last accepted ones (CD-16). */
enum FxRateVerdict: string
{
    case Accepted = 'accepted';
    /** A move above the hold limit: kept for Finance to confirm, not used. */
    case Held = 'held';
}
