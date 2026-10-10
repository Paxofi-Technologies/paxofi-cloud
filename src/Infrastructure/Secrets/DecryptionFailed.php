<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use RuntimeException;

/**
 * A stored secret could not be decrypted: unknown key ID, malformed envelope,
 * tampered ciphertext, or ciphertext bound to a different record (AAD).
 * Never retried; raises an operator alert.
 */
final class DecryptionFailed extends RuntimeException
{
}
