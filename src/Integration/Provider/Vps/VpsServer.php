<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

final class VpsServer
{
    public function __construct(
        public readonly string $providerServerId,
        public readonly ?int $serviceId,
        public readonly ?string $environment,
        public readonly VpsServerStatus $status,
        public readonly ?string $ipv4,
        public readonly ?string $ipv6,
    ) {
    }
}
