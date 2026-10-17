<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Application\Email\EmailReceipt;

/** A provider stand-in that fails with the scripted kinds, then accepts. */
final class ScriptedEmailSender implements TransactionalEmailSender
{
    public int $calls = 0;

    /** @var list<EmailMessage> */
    public array $sent = [];

    /** @param list<DeliveryFailureKind> $failures consumed one per call */
    public function __construct(private readonly string $name = 'postmark', private array $failures = [])
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(EmailMessage $message): EmailReceipt
    {
        ++$this->calls;
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw new EmailDeliveryFailed($failure, $this->name, 'scripted ' . $failure->value);
        }
        $this->sent[] = $message;

        return new EmailReceipt($this->name, 'msg-' . $this->calls);
    }
}
