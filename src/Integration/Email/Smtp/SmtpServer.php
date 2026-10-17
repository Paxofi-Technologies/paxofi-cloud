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

    private const string HOST_NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** The name the certificate is verified against (and sent as SNI). */
    public string $tlsName;

    /**
     * @param string|null $tlsName certificate name when it differs from the host:
     *                             only the host itself or one of its parent
     *                             domains (E-13), e.g. mail.example.com → example.com
     */
    public function __construct(public string $host, public int $port, ?string $tlsName = null)
    {
        if (strlen($host) > 253 || preg_match(self::HOST_NAME, $host) !== 1) {
            throw new \InvalidArgumentException('SMTP_HOST must be a lower-case DNS host name (IP addresses are not accepted, TLS needs a name to verify).');
        }
        $tlsName ??= $host;
        if (preg_match(self::HOST_NAME, $tlsName) !== 1 || ($tlsName !== $host && !str_ends_with($host, '.' . $tlsName))) {
            throw new \InvalidArgumentException('SMTP_TLS_NAME must be SMTP_HOST or one of its parent domains.');
        }
        $this->tlsName = $tlsName;
        if ($port !== self::IMPLICIT_TLS_PORT && $port !== self::STARTTLS_PORT) {
            throw new \InvalidArgumentException('SMTP_PORT must be 465 (implicit TLS) or 587 (STARTTLS).');
        }
    }

    public function implicitTls(): bool
    {
        return $this->port === self::IMPLICIT_TLS_PORT;
    }
}
