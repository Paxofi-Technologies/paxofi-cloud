<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Secrets\DecryptionFailed;
use PaxofiCloud\Infrastructure\Secrets\InvalidKeyRing;

/**
 * `bin/paxoficloud credentials:reencrypt` — key rotation step 2: re-encrypts
 * every credential still on an older key with the active key. Idempotent;
 * exits 1 if any credential is still on another key afterwards.
 */
final readonly class CredentialsReencryptCommand implements Command
{
    public function __construct(private CredentialStoreAccess $access, private Console $console)
    {
    }

    public function name(): string
    {
        return 'credentials:reencrypt';
    }

    public function execute(array $arguments): int
    {
        try {
            $store = $this->access->store();
            $count = $store->reencryptAll();
        } catch (InvalidKeyRing | DecryptionFailed $error) {
            $this->console->error('Re-encryption stopped: ' . $error->getMessage());

            return 1;
        }

        $remaining = array_filter($store->summaries(), $store->needsReencryption(...));
        $this->console->line(sprintf('Re-encrypted %d credential(s).', $count));
        if ($remaining !== []) {
            $this->console->error(sprintf('%d credential(s) are still on an older key (changed concurrently?); run the command again.', count($remaining)));

            return 1;
        }

        return 0;
    }
}
