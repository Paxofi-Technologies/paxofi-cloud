<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

use PaxofiCloud\Integration\Provider\Http\HostResolver;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\SystemHostResolver;

/**
 * Opens the relay connection with the same egress rules as ProviderHttpClient
 * (threat model E-13, M-14): we resolve the host ourselves, refuse it if any
 * address is non-public, and connect to the vetted address while verifying
 * the certificate against the configured name (SMTP_TLS_NAME, else the host).
 */
final readonly class StreamSmtpConnector implements SmtpConnector
{
    public function __construct(
        private HostResolver $resolver = new SystemHostResolver(),
        private float $connectTimeoutSeconds = 10.0,
        private int $ioTimeoutSeconds = 30,
    ) {
    }

    public function connect(SmtpServer $server): SmtpChannel
    {
        $address = $this->vettedAddress($server->host);
        $target = sprintf(str_contains($address, ':') ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $address, $server->port);
        $context = stream_context_create(['ssl' => self::tlsOptions($server->tlsName)]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $this->connectTimeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new SmtpTransportFailure('SMTP server unreachable.');
        }
        stream_set_timeout($stream, $this->ioTimeoutSeconds);

        $channel = new StreamSmtpChannel($stream);
        if ($server->implicitTls()) {
            $channel->enableTls();
        }

        return $channel;
    }

    /** @return array<string, mixed> */
    public static function tlsOptions(string $host): array
    {
        return [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
    }

    public function vettedAddress(string $host): string
    {
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new SmtpTransportFailure('SMTP host did not resolve.');
        }
        foreach ($addresses as $ip) {
            if (!ProviderHttpClient::isPublicAddress($ip)) {
                throw new SmtpTransportFailure('SMTP host resolved to a non-public address; connection refused.');
            }
        }

        return $addresses[0];
    }
}
