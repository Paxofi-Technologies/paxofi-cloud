<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Application\Email\EmailReceipt;

/** Hands one transactional email to a provider (threat model E-1). */
interface TransactionalEmailSender
{
    public function name(): string;

    /** @throws EmailDeliveryFailed */
    public function send(EmailMessage $message): EmailReceipt;
}
