<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Whois;

/** One TCP exchange with an already vetted IP address on port 43. Injected so WhoisClient is testable without a network. */
interface WhoisSocket
{
    /**
     * Connects to the address on port 43, writes the payload and reads until
     * the server closes, the byte cap is reached or the total time runs out.
     *
     * @throws \PaxofiCloud\Application\Domains\WhoisUnavailable CONNECT_FAILED or TIMEOUT
     */
    public function exchange(string $ip, string $payload, int $connectTimeoutMs, int $totalTimeoutMs, int $maxBytes): string;
}
