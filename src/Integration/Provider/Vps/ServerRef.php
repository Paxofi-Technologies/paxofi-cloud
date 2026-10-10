<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/**
 * Identifies a customer server for a mutating call: our service ID (loaded
 * from the tenant-scoped service record, never from the client) plus the
 * provider's server ID stored at provisioning time. Adapters verify that the
 * provider-side ownership labels still match before acting (threat model D-4).
 */
final class ServerRef
{
    public function __construct(
        public readonly int $serviceId,
        public readonly string $providerServerId,
    ) {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('Service ID must be positive.');
        }
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $providerServerId) !== 1) {
            throw new \InvalidArgumentException('Provider server ID has an unexpected format.');
        }
    }
}
