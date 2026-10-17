<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Email\Smtp;

use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Integration\Email\Sender;
use PaxofiCloud\Integration\Email\Smtp\SmtpEmailSender;
use PaxofiCloud\Integration\Email\Smtp\SmtpServer;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Tests\Support\ScriptedSmtpChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Threat model E-13, M-12, M-13. */
final class SmtpEmailSenderTest extends TestCase
{
    private const string PASSWORD = 'S3cret-relay-pass';

    public function testStartTlsHappyPathAuthenticatesOnlyAfterEncryption(): void
    {
        $channel = new ScriptedSmtpChannel([
            '220 mail.example.ng ESMTP',
            '250-mail.example.ng', '250-SIZE 52428800', '250 STARTTLS',
            '220 Ready to start TLS',
            '250-mail.example.ng', '250-AUTH PLAIN LOGIN', '250 SIZE 52428800',
            '235 Authentication succeeded',
            '250 OK', '250 Accepted', '354 Go ahead', '250 OK id=1abc', '221 Bye',
        ]);

        $receipt = $this->sender($channel, 587)->send(self::message());

        self::assertSame('smtp', $receipt->provider);
        self::assertSame('<outbox-1@paxofi.com>', $receipt->messageId);
        self::assertSame([
            'EHLO paxofi.com',
            'STARTTLS',
            'EHLO paxofi.com',
            'AUTH PLAIN ' . base64_encode("\0no-reply@paxofi.com\0" . self::PASSWORD),
            'MAIL FROM:<no-reply@paxofi.com>',
            'RCPT TO:<ada@example.ng>',
            'DATA',
            'QUIT',
        ], $channel->commands());
        foreach ($channel->writes as $i => $write) {
            if (str_starts_with($write['data'], 'AUTH')) {
                self::assertTrue($write['encrypted'], 'AUTH must only ever be sent inside TLS');
            }
            if ($i < 2) {
                self::assertFalse($write['encrypted']);
            }
        }
        self::assertTrue($channel->closed);
        self::assertStringContainsString("Subject: Verify your email\r\n", (string) $channel->dataPayload());
    }

    public function testImplicitTlsOnPort465SkipsStartTls(): void
    {
        $channel = new ScriptedSmtpChannel([
            '220 ready', '250-mail.example.ng', '250 AUTH PLAIN',
            '235 ok', '250 ok', '250 ok', '354 go', '250 ok', '221 bye',
        ], encrypted: true);

        $this->sender($channel, 465)->send(self::message());

        self::assertNotContains('STARTTLS', $channel->commands());
        self::assertSame(465, $channel->connectedTo?->port);
    }

    public function testNoStartTlsMeansTheCredentialsAreNeverSent(): void
    {
        $channel = new ScriptedSmtpChannel(['220 ready', '250-mail.example.ng', '250 AUTH PLAIN LOGIN']);

        $failure = $this->failure($channel, 587);

        self::assertSame(DeliveryFailureKind::Configuration, $failure->kind);
        self::assertSame(['EHLO paxofi.com'], $channel->commands());
        self::assertStringNotContainsString(base64_encode(self::PASSWORD), $channel->transcript());
        self::assertStringNotContainsString('AUTH', $channel->transcript());
        self::assertTrue($channel->closed);
    }

    public function testFallsBackToAuthLogin(): void
    {
        $channel = new ScriptedSmtpChannel([
            '220 ready', '250-x', '250 AUTH LOGIN',
            '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 ok',
            '250 ok', '250 ok', '354 go', '250 ok', '221 bye',
        ], encrypted: true);

        $this->sender($channel, 465)->send(self::message());

        self::assertSame(['AUTH LOGIN', base64_encode('no-reply@paxofi.com'), base64_encode(self::PASSWORD)], array_slice($channel->commands(), 1, 3));
    }

    public function testRefusesWhenNoSupportedAuthMechanismIsOffered(): void
    {
        $channel = new ScriptedSmtpChannel(['220 ready', '250-x', '250 AUTH CRAM-MD5'], encrypted: true);

        self::assertSame(DeliveryFailureKind::Configuration, $this->failure($channel, 465)->kind);
    }

    /** @return iterable<string, array{int, DeliveryFailureKind}> */
    public static function replyCodes(): iterable
    {
        yield 'temporary failure' => [451, DeliveryFailureKind::Transient];
        yield 'mailbox busy' => [450, DeliveryFailureKind::Transient];
        yield 'auth failed' => [535, DeliveryFailureKind::Configuration];
        yield 'auth required' => [530, DeliveryFailureKind::Configuration];
        yield 'no such user' => [550, DeliveryFailureKind::Rejected];
        yield 'policy rejection' => [554, DeliveryFailureKind::Rejected];
        yield 'unexpected success code' => [221, DeliveryFailureKind::Transient];
    }

    #[DataProvider('replyCodes')]
    public function testRefusalsMapToRetryAndFailoverDecisions(int $code, DeliveryFailureKind $kind): void
    {
        self::assertSame($kind, SmtpEmailSender::classify($code));
    }

    public function testRecipientRefusalIsRejectedWithoutEchoingTheServerText(): void
    {
        $channel = new ScriptedSmtpChannel([
            '220 ready', '250-x', '250 AUTH PLAIN', '235 ok', '250 ok',
            '550 5.1.1 <ada@example.ng>: Recipient address rejected',
        ], encrypted: true);

        $failure = $this->failure($channel, 465);

        self::assertSame(DeliveryFailureKind::Rejected, $failure->kind);
        self::assertSame('SMTP server refused RCPT TO (reply 550).', $failure->getMessage());
        self::assertStringNotContainsString('ada@', $failure->getMessage());
    }

    public function testWrongPasswordIsAConfigurationErrorThatNeverContainsThePassword(): void
    {
        $channel = new ScriptedSmtpChannel(['220 ready', '250-x', '250 AUTH PLAIN', '535 5.7.8 Authentication credentials invalid'], encrypted: true);

        $failure = $this->failure($channel, 465);

        self::assertSame(DeliveryFailureKind::Configuration, $failure->kind);
        self::assertStringNotContainsString(self::PASSWORD, $failure->getMessage() . print_r($failure->getTrace(), true));
    }

    public function testUnreachableServerIsTransient(): void
    {
        $failure = $this->failure(new ScriptedSmtpChannel([], refuseConnect: true), 587);

        self::assertSame(DeliveryFailureKind::Transient, $failure->kind);
    }

    public function testConnectionDroppedMidDialogueIsTransientAndClosesTheChannel(): void
    {
        $channel = new ScriptedSmtpChannel(['220 ready', '250-x', '250 AUTH PLAIN', '235 ok'], encrypted: true);

        $failure = $this->failure($channel, 465);

        self::assertSame(DeliveryFailureKind::Transient, $failure->kind);
        self::assertTrue($channel->closed);
    }

    public function testMalformedReplyIsTransient(): void
    {
        $channel = new ScriptedSmtpChannel(['hello there'], encrypted: true);

        self::assertSame(DeliveryFailureKind::Transient, $this->failure($channel, 465)->kind);
    }

    public function testUsernameWithControlCharactersIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpEmailSender(new ScriptedSmtpChannel([]), new SmtpServer('mail.example.ng', 587), "user\r\nRCPT TO:<x@y.z>", new ProviderCredential('smtp-relay', self::PASSWORD), self::from());
    }

    public function testDebugOutputDoesNotRevealThePassword(): void
    {
        $dump = print_r($this->sender(new ScriptedSmtpChannel([]), 587), true);

        self::assertStringNotContainsString(self::PASSWORD, $dump);
    }

    private function failure(ScriptedSmtpChannel $channel, int $port): EmailDeliveryFailed
    {
        try {
            $this->sender($channel, $port)->send(self::message());
        } catch (EmailDeliveryFailed $e) {
            self::assertSame('smtp', $e->provider);

            return $e;
        }
        self::fail('Expected the send to fail.');
    }

    private function sender(ScriptedSmtpChannel $channel, int $port): SmtpEmailSender
    {
        return new SmtpEmailSender(
            $channel,
            new SmtpServer('mail.example.ng', $port),
            'no-reply@paxofi.com',
            new ProviderCredential('smtp-relay', self::PASSWORD),
            self::from(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-17T12:00:00Z'),
        );
    }

    private static function from(): Sender
    {
        return new Sender('PaxofiCloud', new EmailAddress('no-reply@paxofi.com'));
    }

    private static function message(): EmailMessage
    {
        return new EmailMessage(new EmailAddress('ada@example.ng'), 'Verify your email', "Hello Ada\n.\nBye", '<p>Hello Ada</p>', 'verify-email', 'outbox-1');
    }
}
