<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

use PaxofiCloud\Integration\Provider\ProviderAdapter;
use PaxofiCloud\Integration\Provider\ProviderError;

/**
 * Provider-neutral VPS contract (threat model D-2). Hetzner Cloud is the
 * primary implementation and Vultr the secondary; both run the same
 * contract test suite. An adapter instance is bound to one provider
 * project/account and one environment (D-3), and labels every server it
 * creates with both so it can verify ownership before acting (D-4).
 *
 * Every method returns the documented result or throws ProviderError
 * (classified per PRV-005) or OwnershipMismatch.
 */
interface VpsProvider extends ProviderAdapter
{
    /**
     * Idempotent create (PRV-003, D-7): if a server for this service already
     * exists it is returned (adoptedExisting = true) instead of creating another.
     *
     * @throws ProviderError
     */
    public function createServer(CreateVpsServer $spec): VpsCreation;

    /**
     * Removes temporary provider resources used only during creation (for
     * example uploaded SSH keys). Called by the job after the create action
     * has succeeded; safe to call repeatedly.
     *
     * @throws ProviderError
     */
    public function finishCreation(int $serviceId): void;

    /** @throws ProviderError */
    public function findServerForService(int $serviceId): ?VpsServer;

    /** @throws ProviderError|OwnershipMismatch */
    public function power(ServerRef $ref, PowerAction $action): VpsAction;

    /**
     * Idempotent delete: returns null when the server is already gone.
     *
     * @throws ProviderError|OwnershipMismatch
     */
    public function deleteServer(ServerRef $ref): ?VpsAction;

    /** @throws ProviderError */
    public function pollAction(string $actionId): VpsAction;
}
