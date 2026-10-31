<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Payments\Gateway;

/** The configured adapter for each gateway; only the payments worker builds one (PY-3). */
interface PaymentGateways
{
    public function for(Gateway $gateway): PaymentGateway;
}
