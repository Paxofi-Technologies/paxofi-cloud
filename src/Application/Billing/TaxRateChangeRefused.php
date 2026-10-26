<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

/** A tax rate change was refused; the reason is a fixed code (CD-28). */
final class TaxRateChangeRefused extends \RuntimeException
{
    public const string INVALID = 'invalid';
    public const string STEP_UP = 'step_up_failed';
    public const string THROTTLED = 'throttled';
    public const string START_IN_PAST = 'start_in_past';
    public const string DUPLICATE_START = 'duplicate_start';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Tax rate change refused: ' . $reason);
    }
}
