<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

/**
 * Where the worker relays mail (threat model E-13). Only 465 (implicit TLS)
 * and 587 (STARTTLS) are accepted: port 25 is server-to-server and usually
 * blocked by cloud providers, and every other port is refused so SMTP_HOST
 * can never be used to probe internal services (M-14).
 */
final readonly class SmtpServer
{
    public const int IMPLICIT_TLS_PORT = 465;
    public const int STARTTLS_PORT = 587;

    public function __construct(public string $host, public int $port)
    {
        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new \InvalidArgumentException('SMTP_HOST must be a lower-case DNS host name (IP addresses are not accepted, TLS needs a name to verify).');
        }
        if ($port !== self::IMPLICIT_TLS_PORT && $port !== self::STARTTLS_PORT) {
            throw new \InvalidArgumentException('SMTP_PORT must be 465 (implicit TLS) or 587 (STARTTLS).');
        }
    }

    public function implicitTls(): bool
    {
        return $this->port === self::IMPLICIT_TLS_PORT;
    }
}
