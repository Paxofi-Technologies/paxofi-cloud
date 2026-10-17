<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Email;

use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Application\Email\FailoverEmailSender;
use PaxofiCloud\Tests\Support\ScriptedEmailSender;
use PHPUnit\Framework\TestCase;

/** M-07: fail over on transient failures only. */
final class FailoverEmailSenderTest extends TestCase
{
    public function testUsesThePrimaryWhenItAccepts(): void
    {
        $secondary = self::sender('resend', null);
        $receipt = (new FailoverEmailSender(self::sender('postmark', null), $secondary))->send(self::message());

        self::assertSame('postmark', $receipt->provider);
        self::assertSame(0, $secondary->calls);
    }

    public function testFailsOverOnATransientFailure(): void
    {
        $receipt = (new FailoverEmailSender(self::sender('postmark', DeliveryFailureKind::Transient), self::sender('resend', null)))->send(self::message());

        self::assertSame('resend', $receipt->provider);
    }

    public function testWhenBothFailTheErrorNamesBothAndStaysTransient(): void
    {
        try {
            (new FailoverEmailSender(self::sender('smtp', DeliveryFailureKind::Transient), self::sender('postmark', DeliveryFailureKind::Rejected)))->send(self::message());
            self::fail('Expected a failure');
        } catch (EmailDeliveryFailed $e) {
            self::assertSame(DeliveryFailureKind::Transient, $e->kind, 'The primary may recover; the worker must retry');
            self::assertSame('smtp: scripted transient Failover postmark: scripted rejected', $e->getMessage());
        }
    }

    public function testDoesNotFailOverOnRejectionOrConfigurationErrors(): void
    {
        foreach ([DeliveryFailureKind::Rejected, DeliveryFailureKind::Configuration] as $kind) {
            $secondary = self::sender('resend', null);
            try {
                (new FailoverEmailSender(self::sender('postmark', $kind), $secondary))->send(self::message());
                self::fail('Expected the primary failure to surface');
            } catch (EmailDeliveryFailed $e) {
                self::assertSame($kind, $e->kind);
            }
            self::assertSame(0, $secondary->calls, $kind->value . ' must not fail over');
        }
    }

    private static function message(): EmailMessage
    {
        return new EmailMessage(new EmailAddress('a@b.ng'), 'Subject', 'text', '<p>html</p>', 'verify-email', 'id1');
    }

    private static function sender(string $name, ?DeliveryFailureKind $failure): ScriptedEmailSender
    {
        return new ScriptedEmailSender($name, $failure === null ? [] : [$failure]);
    }
}
