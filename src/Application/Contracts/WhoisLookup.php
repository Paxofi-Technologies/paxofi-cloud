<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * A port-43 WHOIS query (THREAT-MODEL-DOMAIN-SEARCH.md DS-16), run only in
 * the provider worker. The reply is returned for reading and is never
 * stored or logged by the caller.
 */
interface WhoisLookup
{
    /**
     * @param string $server a DNS host name (never an IP); port 43 is always used
     * @param string $query a validated domain or extension, without CR, LF or spaces
     * @throws \PaxofiCloud\Application\Domains\WhoisUnavailable with a fixed reason code
     */
    public function query(string $server, string $query): string;
}
