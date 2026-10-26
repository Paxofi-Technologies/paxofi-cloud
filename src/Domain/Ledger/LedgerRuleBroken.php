<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

/** An entry that would break a ledger rule (unbalanced, one-sided, wrong sub-ledger use). Never posted. */
final class LedgerRuleBroken extends \DomainException
{
}
