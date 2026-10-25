<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Orders;

final class InvalidTransition extends \DomainException
{
    public function __construct(public readonly string $machine, public readonly string $from, public readonly string $to)
    {
        parent::__construct("Invalid {$machine} transition {$from} -> {$to}.");
    }
}
