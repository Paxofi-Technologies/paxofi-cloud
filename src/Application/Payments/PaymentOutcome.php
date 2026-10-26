<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

/**
 * What a gateway's verify call says happened (SRS §5.2). Anything a gateway
 * reports that we do not recognise is Pending, to be verified again; it is
 * never Succeeded.
 */
enum PaymentOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Pending = 'pending';
    case NotFound = 'not_found';
}
