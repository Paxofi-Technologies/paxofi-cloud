<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

/** Resolves a hostname to its IPv4 and IPv6 addresses. Injected so SSRF checks are testable. */
interface HostResolver
{
    /** @return list<string> every A and AAAA address for the host; empty when it does not resolve */
    public function resolve(string $host): array;
}
