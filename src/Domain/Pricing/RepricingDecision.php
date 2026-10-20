<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/** What happens to an enabled item's price after a sync or rate update (CD-15). */
enum RepricingDecision: string
{
    /** The derived price equals the current one. */
    case NoChange = 'no_change';
    /** Within the approval threshold: a new price row from 00:00 WAT the next day. */
    case ApplyNextDay = 'apply_next_day';
    /** Above the threshold: a pending change that staff must approve. */
    case NeedsApproval = 'needs_approval';
    /** Cost now exceeds the current price: stop selling at once and ask for approval. */
    case StopSelling = 'stop_selling';
}
