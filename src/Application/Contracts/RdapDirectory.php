<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Which RDAP server answers for an extension (THREAT-MODEL-DOMAIN-SEARCH.md DS-2). */
interface RdapDirectory
{
    /** The validated https base URL, or null when IANA lists none or the list cannot be loaded. */
    public function serverFor(string $tld): ?string;
}
