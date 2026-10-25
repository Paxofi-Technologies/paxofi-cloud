<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

/** A change on the Domain extensions page was refused; the reason is a fixed code. */
final class ExtensionChangeRefused extends \RuntimeException
{
    public const string INVALID = 'invalid';
    public const string STEP_UP = 'step_up_failed';
    public const string THROTTLED = 'throttled';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Extension change refused: ' . $reason);
    }
}
