<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

final class InvalidBillingDetails extends \RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Billing details are invalid.');
    }
}
