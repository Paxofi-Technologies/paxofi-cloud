<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
