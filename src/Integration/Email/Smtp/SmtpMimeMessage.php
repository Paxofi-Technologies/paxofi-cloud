<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Integration\Email\Sender;

/**
 * Builds the RFC 5322 message for DATA (threat model M-13). Headers come only
 * from validated values (EmailAddress, one-line subject, Sender); bodies are
 * CRLF-normalised and quoted-printable encoded, so no input can start a new
 * header or a line made of a single dot.
 */
final class SmtpMimeMessage
{
    public static function messageId(EmailMessage $message, Sender $from): string
    {
        $left = preg_replace('/[^A-Za-z0-9.-]/', '-', $message->idempotencyKey) ?? 'outbox';

        return sprintf('<%s@%s>', $left, $from->address->domain());
    }

    public static function build(EmailMessage $message, Sender $from, \DateTimeImmutable $date, string $boundary): string
    {
        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $boundary) !== 1) {
            throw new \InvalidArgumentException('MIME boundary must be 16-64 alphanumeric characters.');
        }
        $headers = [
            'Date: ' . $date->format(\DateTimeInterface::RFC2822),
            'From: ' . $from->header(),
            'To: <' . $message->to->value . '>',
            'Subject: ' . self::encodeHeader($message->subject),
            'Message-ID: ' . self::messageId($message, $from),
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $parts = [
            self::part($boundary, 'text/plain', $message->textBody),
            self::part($boundary, 'text/html', $message->htmlBody),
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode('', $parts) . '--' . $boundary . "--\r\n";
    }

    /** Escapes lines that start with a dot (RFC 5321 §4.5.2) and appends the end-of-data marker. */
    public static function dataPayload(string $message): string
    {
        $stuffed = preg_replace('/^\./m', '..', $message) ?? $message;

        return rtrim($stuffed, "\r\n") . "\r\n.\r\n";
    }

    private static function part(string $boundary, string $type, string $body): string
    {
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;

        return '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n"
            . quoted_printable_encode($body) . "\r\n";
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $value) === 1 ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
