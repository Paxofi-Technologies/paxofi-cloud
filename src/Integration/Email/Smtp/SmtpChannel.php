<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

/** One open connection to the relay. Implementations throw SmtpTransportFailure on I/O errors and timeouts. */
interface SmtpChannel
{
    public function write(#[\SensitiveParameter] string $data): void;

    /** One reply line without its CRLF. */
    public function readLine(): string;

    /** Upgrades the connection after STARTTLS, with certificate and host-name verification. */
    public function enableTls(): void;

    public function isEncrypted(): bool;

    public function close(): void;
}
