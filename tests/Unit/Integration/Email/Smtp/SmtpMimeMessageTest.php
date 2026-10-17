<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Email\Smtp;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Integration\Email\Sender;
use PaxofiCloud\Integration\Email\Smtp\SmtpMimeMessage;
use PHPUnit\Framework\TestCase;

/** Threat model M-13. */
final class SmtpMimeMessageTest extends TestCase
{
    private const string BOUNDARY = 'b0a1b2c3d4e5f60718293a4b5c6d7e8f';

    public function testBuildsAMultipartMessageWithOnlyCrlfLineEndings(): void
    {
        $mime = $this->build(self::message('Verify your email', "Line one\nLine two\rLine three"));

        self::assertSame(0, preg_match('/(?<!\r)\n|\r(?!\n)/', $mime), 'Every line must end in CRLF');
        self::assertStringStartsWith("Date: Sat, 17 Oct 2026 12:00:00 +0000\r\nFrom: PaxofiCloud <no-reply@paxofi.com>\r\nTo: <ada@example.ng>\r\nSubject: Verify your email\r\nMessage-ID: <outbox-1@paxofi.com>\r\n", $mime);
        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="' . self::BOUNDARY . '"', $mime);
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable", $mime);
        self::assertStringContainsString("Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable", $mime);
        self::assertStringEndsWith('--' . self::BOUNDARY . "--\r\n", $mime);
    }

    public function testNonAsciiSubjectIsEncodedAndBodyIsQuotedPrintable(): void
    {
        $mime = $this->build(self::message('Welcome, Adé', 'Naira ₦ prices'));

        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Welcome, Adé') . '?=', $mime);
        self::assertStringContainsString('Naira =E2=82=A6 prices', $mime);
        self::assertSame(0, preg_match('/[^\x00-\x7f]/', $mime), 'The message on the wire must be 7-bit');
    }

    public function testDotStuffingStopsABodyLineFromEndingDataEarly(): void
    {
        $payload = SmtpMimeMessage::dataPayload($this->build(self::message('Hi', "before\n.\nRCPT TO:<eve@example.com>\n..two")));

        self::assertStringContainsString("\r\n..\r\n", $payload);
        self::assertStringContainsString("\r\n...two", $payload);
        self::assertSame(1, substr_count($payload, "\r\n.\r\n"), 'Only the real end-of-data marker may be a lone dot');
        self::assertStringEndsWith("\r\n.\r\n", $payload);
    }

    public function testMessageIdIsAlwaysSafe(): void
    {
        $message = new EmailMessage(new EmailAddress('ada@example.ng'), 'Hi', 't', 'h', 'verify-email', 'job:42_x');

        self::assertSame('<job-42-x@paxofi.com>', SmtpMimeMessage::messageId($message, self::from()));
    }

    public function testRejectsAnUnsafeBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmtpMimeMessage::build(self::message('Hi', 'x'), self::from(), new \DateTimeImmutable(), "abc\r\nBcc: eve@example.com");
    }

    private function build(EmailMessage $message): string
    {
        return SmtpMimeMessage::build($message, self::from(), new \DateTimeImmutable('2026-10-17T12:00:00Z'), self::BOUNDARY);
    }

    private static function from(): Sender
    {
        return new Sender('PaxofiCloud', new EmailAddress('no-reply@paxofi.com'));
    }

    private static function message(string $subject, string $text): EmailMessage
    {
        return new EmailMessage(new EmailAddress('ada@example.ng'), $subject, $text, '<p>' . $text . '</p>', 'verify-email', 'outbox-1');
    }
}
