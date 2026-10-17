<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Application\Email\EmailReceipt;
use PaxofiCloud\Integration\Email\Sender;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Relays through our own mail server (CEO decision 2026-10-17, threat model
 * E-13). TLS is mandatory before AUTH: on port 587 the client stops if the
 * server does not offer STARTTLS, so the password is never sent in clear
 * (M-12). Failures carry only reply codes, never the server's text.
 */
final readonly class SmtpEmailSender implements TransactionalEmailSender
{
    private const int MAX_REPLY_LINES = 64;

    /** @param \Closure(): \DateTimeImmutable|null $now */
    public function __construct(
        private SmtpConnector $connector,
        private SmtpServer $server,
        private string $username,
        private ProviderCredential $password,
        private Sender $from,
        private ?\Closure $now = null,
    ) {
        if ($username === '' || strlen($username) > 254 || preg_match('/[\x00-\x20\x7f]/', $username) === 1) {
            throw new \InvalidArgumentException('SMTP_USERNAME must be a non-empty value without spaces or control characters.');
        }
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(EmailMessage $message): EmailReceipt
    {
        try {
            $channel = $this->connector->connect($this->server);
        } catch (SmtpTransportFailure $e) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), $e->getMessage(), $e);
        }

        try {
            $this->dialogue($channel, $message);
            $this->quit($channel);
        } catch (SmtpTransportFailure $e) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), $e->getMessage(), $e);
        } finally {
            $channel->close();
        }

        return new EmailReceipt($this->name(), SmtpMimeMessage::messageId($message, $this->from));
    }

    private function dialogue(SmtpChannel $channel, EmailMessage $message): void
    {
        $this->expect($channel, [220], 'greeting');
        $capabilities = $this->ehlo($channel);

        if (!$channel->isEncrypted()) {
            if (!in_array('STARTTLS', $capabilities, true)) {
                throw new EmailDeliveryFailed(DeliveryFailureKind::Configuration, $this->name(), 'SMTP server does not offer STARTTLS; refusing to send credentials in clear.');
            }
            $this->command($channel, 'STARTTLS', [220], 'STARTTLS');
            $channel->enableTls();
            $capabilities = $this->ehlo($channel);
        }

        $this->authenticate($channel, $capabilities);
        $this->command($channel, 'MAIL FROM:<' . $this->from->address->value . '>', [250], 'MAIL FROM');
        $this->command($channel, 'RCPT TO:<' . $message->to->value . '>', [250, 251], 'RCPT TO');
        $this->command($channel, 'DATA', [354], 'DATA');

        $mime = SmtpMimeMessage::build($message, $this->from, $this->now(), bin2hex(random_bytes(16)));
        $channel->write(SmtpMimeMessage::dataPayload($mime));
        $this->expect($channel, [250], 'end of data');
    }

    /** @return list<string> upper-cased capability lines, e.g. "STARTTLS", "AUTH PLAIN LOGIN" */
    private function ehlo(SmtpChannel $channel): array
    {
        $reply = $this->command($channel, 'EHLO ' . $this->from->address->domain(), [250], 'EHLO');

        return array_map(static fn (string $line): string => strtoupper(trim($line)), array_slice($reply->lines, 1));
    }

    /** @param list<string> $capabilities */
    private function authenticate(SmtpChannel $channel, array $capabilities): void
    {
        if (!$channel->isEncrypted()) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Configuration, $this->name(), 'Refusing SMTP AUTH on an unencrypted connection.');
        }
        $mechanisms = [];
        foreach ($capabilities as $line) {
            if (str_starts_with($line, 'AUTH ') || str_starts_with($line, 'AUTH=')) {
                $mechanisms = array_merge($mechanisms, preg_split('/[\s=]+/', substr($line, 5), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command($channel, 'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password->reveal()), [235], 'AUTH');

            return;
        }
        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command($channel, 'AUTH LOGIN', [334], 'AUTH');
            $this->command($channel, base64_encode($this->username), [334], 'AUTH');
            $this->command($channel, base64_encode($this->password->reveal()), [235], 'AUTH');

            return;
        }

        throw new EmailDeliveryFailed(DeliveryFailureKind::Configuration, $this->name(), 'SMTP server offers no supported AUTH mechanism (PLAIN or LOGIN).');
    }

    /** @param list<int> $accepted */
    private function command(SmtpChannel $channel, #[\SensitiveParameter] string $line, array $accepted, string $step): SmtpReply
    {
        $channel->write($line . "\r\n");

        return $this->expect($channel, $accepted, $step);
    }

    /** @param list<int> $accepted */
    private function expect(SmtpChannel $channel, array $accepted, string $step): SmtpReply
    {
        $reply = $this->read($channel);
        if (!in_array($reply->code, $accepted, true)) {
            throw new EmailDeliveryFailed(
                self::classify($reply->code),
                $this->name(),
                sprintf('SMTP server refused %s (reply %d).', $step, $reply->code),
            );
        }

        return $reply;
    }

    private function read(SmtpChannel $channel): SmtpReply
    {
        $lines = [];
        $code = null;
        for ($i = 0; $i < self::MAX_REPLY_LINES; $i++) {
            $line = $channel->readLine();
            if (preg_match('/^([2-5][0-9]{2})(?:([ -])(.*))?$/s', $line, $m) !== 1) {
                throw new SmtpTransportFailure('SMTP server sent a malformed reply.');
            }
            $lineCode = (int) $m[1];
            if ($code !== null && $lineCode !== $code) {
                throw new SmtpTransportFailure('SMTP server sent a malformed reply.');
            }
            $code = $lineCode;
            $lines[] = $m[3] ?? '';
            if (($m[2] ?? '') !== '-') {
                return new SmtpReply($code, $lines);
            }
        }

        throw new SmtpTransportFailure('SMTP reply has too many lines.');
    }

    /** Maps a refusal to the E-1 retry/failover decision. */
    public static function classify(int $code): DeliveryFailureKind
    {
        return match (true) {
            $code >= 400 && $code < 500 => DeliveryFailureKind::Transient,
            in_array($code, [530, 534, 535, 538], true) => DeliveryFailureKind::Configuration,
            $code >= 500 => DeliveryFailureKind::Rejected,
            // A 2xx/3xx where another reply was expected: protocol confusion, retry later.
            default => DeliveryFailureKind::Transient,
        };
    }

    private function quit(SmtpChannel $channel): void
    {
        try {
            $channel->write("QUIT\r\n");
            $channel->readLine();
        } catch (SmtpTransportFailure) {
            // The message is already accepted; a lost QUIT reply changes nothing.
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
