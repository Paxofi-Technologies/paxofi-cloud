<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

use PaxofiCloud\Application\Contracts\TransactionalEmailSender;

/**
 * Primary first; the secondary is used only when the primary fails
 * transiently (timeout, 5xx, 429). A rejection or a configuration error is
 * returned as-is: retrying a bad request elsewhere hides a bug, and an auth
 * error must be fixed, not routed around (threat model E-1).
 */
final readonly class FailoverEmailSender implements TransactionalEmailSender
{
    public function __construct(
        private TransactionalEmailSender $primary,
        private ?TransactionalEmailSender $secondary,
    ) {
    }

    public function name(): string
    {
        return $this->primary->name() . ($this->secondary === null ? '' : '+' . $this->secondary->name());
    }

    public function send(EmailMessage $message): EmailReceipt
    {
        try {
            return $this->primary->send($message);
        } catch (EmailDeliveryFailed $failure) {
            if ($this->secondary === null || $failure->kind !== DeliveryFailureKind::Transient) {
                throw $failure;
            }
        }

        return $this->secondary->send($message);
    }
}
