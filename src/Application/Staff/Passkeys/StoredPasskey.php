<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/** A registered staff passkey: public data only (migration 0008). */
final readonly class StoredPasskey
{
    public function __construct(
        public string $id,
        public string $staffId,
        public string $credentialId,
        public string $publicKey,
        public int $algorithm,
        public int $signCount,
        public string $aaguid,
        public string $transports,
        public bool $backupEligible,
        public bool $backupState,
        public string $userHandle,
    ) {
    }
}
