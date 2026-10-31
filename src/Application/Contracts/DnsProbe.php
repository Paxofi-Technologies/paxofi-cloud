<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * The DNS step of the domain lookup (THREAT-MODEL-DOMAIN-SEARCH.md DS-14),
 * run only in the provider worker. An NS record proves a name is registered;
 * no NS record proves nothing, so a false answer never means "available".
 */
interface DnsProbe
{
    /** True only when the name has at least one NS record. */
    public function hasNameservers(string $domain): bool;
}
