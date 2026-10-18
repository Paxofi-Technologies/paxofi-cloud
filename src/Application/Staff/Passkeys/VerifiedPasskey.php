<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/** The result of a verified registration ceremony, ready to store. */
final readonly class VerifiedPasskey
{
    /** @param list<string> $transports */
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $algorithm,
        public int $signCount,
        public string $aaguid,
        public array $transports,
        public bool $backupEligible,
        public bool $backupState,
    ) {
    }
}
