<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Whois;

use PaxofiCloud\Application\Domains\WhoisUnavailable;

/** The real port-43 exchange over a PHP stream socket, connecting to an IP literal only (DS-16). */
final readonly class StreamWhoisSocket implements WhoisSocket
{
    public function exchange(string $ip, string $payload, int $connectTimeoutMs, int $totalTimeoutMs, int $maxBytes): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new WhoisUnavailable(WhoisUnavailable::CONNECT_FAILED);
        }
        $deadline = hrtime(true) + $totalTimeoutMs * 1_000_000;
        $target = sprintf(str_contains($ip, ':') ? 'tcp://[%s]:43' : 'tcp://%s:43', $ip);
        $socket = @stream_socket_client($target, $errno, $error, $connectTimeoutMs / 1000, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new WhoisUnavailable(hrtime(true) >= $deadline ? WhoisUnavailable::TIMEOUT : WhoisUnavailable::CONNECT_FAILED);
        }
        try {
            stream_set_blocking($socket, true);
            if (!self::setTimeout($socket, $deadline) || @fwrite($socket, $payload) !== strlen($payload)) {
                throw new WhoisUnavailable(WhoisUnavailable::CONNECT_FAILED);
            }
            $reply = '';
            while (strlen($reply) < $maxBytes && !feof($socket)) {
                if (!self::setTimeout($socket, $deadline)) {
                    throw new WhoisUnavailable(WhoisUnavailable::TIMEOUT);
                }
                $chunk = @fread($socket, max(1, min(8192, $maxBytes - strlen($reply))));
                if ($chunk === false) {
                    throw new WhoisUnavailable(WhoisUnavailable::CONNECT_FAILED);
                }
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    throw new WhoisUnavailable(WhoisUnavailable::TIMEOUT);
                }
                $reply .= $chunk;
            }

            return $reply;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function setTimeout($socket, int $deadline): bool
    {
        $left = intdiv($deadline - hrtime(true), 1000);
        if ($left <= 0) {
            return false;
        }

        return stream_set_timeout($socket, intdiv($left, 1_000_000), $left % 1_000_000);
    }
}
