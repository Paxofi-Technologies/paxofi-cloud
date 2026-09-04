<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

/**
 * Immutable request context passed from the PCF HTTP/application boundary.
 */
final readonly class RequestContext
{
    public function __construct(
        public IdentityId $identityId,
        public TenantId $tenantId,
        public string $correlationId,
    ) {
        if ($this->correlationId === '') {
            throw new \InvalidArgumentException('Correlation ID must not be empty.');
        }
    }
}
