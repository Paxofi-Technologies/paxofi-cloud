<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Order\Order;

interface OrderWriter
{
    public function create(Order $order, string $idempotencyKey): void;
}
