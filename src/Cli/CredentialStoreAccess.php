<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;

/**
 * Builds the credential store only when a credentials command actually runs,
 * so `migrate` and other commands work on hosts that do not hold the
 * key-encryption keys (threat model D-5).
 */
final class CredentialStoreAccess
{
    private ?MysqlProviderCredentialStore $store = null;

    /** @param \Closure(): MysqlProviderCredentialStore $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function store(): MysqlProviderCredentialStore
    {
        return $this->store ??= ($this->factory)();
    }
}
