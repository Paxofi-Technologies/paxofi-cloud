<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

use RuntimeException;

/**
 * The provider-side server does not carry the labels of the service we are
 * acting for. The call is aborted before anything changes; the job goes to
 * NEEDS_ATTENTION and a security alert is raised (threat model V-07).
 */
final class OwnershipMismatch extends RuntimeException
{
    public function __construct(public readonly ServerRef $ref, string $reason)
    {
        parent::__construct(sprintf(
            'Server %s is not owned by service %d in this environment: %s.',
            $ref->providerServerId,
            $ref->serviceId,
            $reason,
        ));
    }
}
