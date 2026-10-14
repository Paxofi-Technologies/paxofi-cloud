<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

enum DeliveryFailureKind: string
{
    /** Timeout, 5xx or 429: retry later, and the other provider may be tried (threat model E-1). */
    case Transient = 'transient';
    /** 401/403: our credential or account is wrong. Retry later; never fail over (E-1). */
    case Configuration = 'configuration';
    /** Any other 4xx: the provider will never accept this message. Dead-letter it. */
    case Rejected = 'rejected';
}
