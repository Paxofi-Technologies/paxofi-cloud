<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Authenticated encryption of small secrets bound to the record they belong to. */
interface SecretEncryption
{
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): string;

    /** @throws \RuntimeException when the envelope is tampered with, from another record or under an unknown key */
    public function decrypt(string $envelope, string $associatedData): string;
}
