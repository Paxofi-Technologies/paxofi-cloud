<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Pre-checks whether one domain name is registered (DS-1, DS-3). Never throws for a provider problem. */
interface DomainLookup
{
    /** @return 'available'|'taken'|'unknown' */
    public function check(string $domain, string $server): string;
}
