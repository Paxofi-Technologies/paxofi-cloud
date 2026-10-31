<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/** A change on the admin Domain search page was refused; the reason is a fixed code. */
final class DomainSearchChangeRefused extends \RuntimeException
{
    public const string INVALID = 'invalid';
    public const string NOT_ON_SALE = 'not_on_sale';
    public const string STEP_UP = 'step_up_failed';
    public const string THROTTLED = 'throttled';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Domain search change refused: ' . $reason);
    }
}
