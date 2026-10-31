<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Dns;

use PaxofiCloud\Application\Contracts\DnsProbe;

/** NS lookup through the system resolver (DS-14). Any error or empty answer is "no evidence". */
final readonly class SystemDnsProbe implements DnsProbe
{
    public function hasNameservers(string $domain): bool
    {
        if (preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain) !== 1) {
            return false;
        }
        // A trailing dot stops the resolver from appending a search domain.
        $records = @dns_get_record($domain . '.', DNS_NS);
        if (!is_array($records)) {
            return false;
        }
        foreach ($records as $record) {
            $host = $record['host'] ?? null;
            if (($record['type'] ?? null) === 'NS' && is_string($host) && strcasecmp(rtrim($host, '.'), $domain) === 0) {
                return true;
            }
        }

        return false;
    }
}
