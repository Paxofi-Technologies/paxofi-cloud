<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

final class BillingRefused extends \RuntimeException
{
    public const string NOT_OWNER = 'not_account_owner';
    public const string TOO_MANY = 'too_many_requests';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
