<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/**
 * Result of createServer. $action is null when an existing server was adopted
 * (nothing new was started). $rootPassword is set only when the provider
 * generated one (no SSH key given); callers must encrypt it immediately and
 * show it once (threat model D-8).
 */
final class VpsCreation
{
    public function __construct(
        public readonly VpsServer $server,
        public readonly ?VpsAction $action,
        public readonly bool $adoptedExisting,
        #[\SensitiveParameter] public readonly ?string $rootPassword = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'server' => $this->server,
            'action' => $this->action,
            'adoptedExisting' => $this->adoptedExisting,
            'rootPassword' => $this->rootPassword === null ? null : '[redacted]',
        ];
    }
}
