<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Payments\PaymentReference;

/** Where to send the customer to pay; the URL host is checked by the adapter against the gateway's own checkout host. */
final readonly class HostedCheckout
{
    public function __construct(public PaymentReference $reference, public string $url)
    {
    }
}
