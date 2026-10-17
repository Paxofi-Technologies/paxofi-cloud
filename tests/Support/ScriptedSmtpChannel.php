<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Integration\Email\Smtp\SmtpChannel;
use PaxofiCloud\Integration\Email\Smtp\SmtpConnector;
use PaxofiCloud\Integration\Email\Smtp\SmtpServer;
use PaxofiCloud\Integration\Email\Smtp\SmtpTransportFailure;

/**
 * A fake relay: replies are queued up front and every write is recorded with
 * whether the channel was encrypted at that moment.
 */
final class ScriptedSmtpChannel implements SmtpChannel, SmtpConnector
{
    /** @var list<array{data: string, encrypted: bool}> */
    public array $writes = [];
    public bool $closed = false;
    public ?SmtpServer $connectedTo = null;

    /** @param list<string> $replies */
    public function __construct(private array $replies, private bool $encrypted = false, private bool $refuseConnect = false)
    {
    }

    public function connect(SmtpServer $server): SmtpChannel
    {
        if ($this->refuseConnect) {
            throw new SmtpTransportFailure('SMTP server unreachable.');
        }
        $this->connectedTo = $server;

        return $this;
    }

    public function write(#[\SensitiveParameter] string $data): void
    {
        $this->writes[] = ['data' => $data, 'encrypted' => $this->encrypted];
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw new SmtpTransportFailure('SMTP connection closed while reading.');
        }

        return array_shift($this->replies);
    }

    public function enableTls(): void
    {
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<string> the command lines written, without CRLF (DATA payload excluded) */
    public function commands(): array
    {
        $lines = [];
        foreach ($this->writes as $w) {
            if (str_ends_with($w['data'], "\r\n.\r\n")) {
                continue;
            }
            $lines[] = rtrim($w['data'], "\r\n");
        }

        return $lines;
    }

    public function dataPayload(): ?string
    {
        foreach ($this->writes as $w) {
            if (str_ends_with($w['data'], "\r\n.\r\n")) {
                return $w['data'];
            }
        }

        return null;
    }

    public function transcript(): string
    {
        return implode('', array_column($this->writes, 'data'));
    }
}
