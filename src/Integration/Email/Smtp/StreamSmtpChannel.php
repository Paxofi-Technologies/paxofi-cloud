<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

/** A socket stream to the relay; TLS settings come from the stream context. */
final class StreamSmtpChannel implements SmtpChannel
{
    private const int MAX_LINE_BYTES = 1000;

    private bool $encrypted = false;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    public function write(#[\SensitiveParameter] string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($this->stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                $this->throwIfTimedOut();
                throw new SmtpTransportFailure('SMTP connection closed while writing.');
            }
            $written += $chunk;
        }
    }

    public function readLine(): string
    {
        $line = @fgets($this->stream, self::MAX_LINE_BYTES + 3);
        if ($line === false) {
            $this->throwIfTimedOut();
            throw new SmtpTransportFailure('SMTP connection closed while reading.');
        }
        if (!str_ends_with($line, "\n")) {
            throw new SmtpTransportFailure('SMTP reply line too long.');
        }

        return rtrim($line, "\r\n");
    }

    public function enableTls(): void
    {
        $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($ok !== true) {
            throw new SmtpTransportFailure('SMTP TLS handshake or certificate verification failed.');
        }
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function throwIfTimedOut(): void
    {
        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out']) {
            throw new SmtpTransportFailure('SMTP server timed out.');
        }
    }
}
