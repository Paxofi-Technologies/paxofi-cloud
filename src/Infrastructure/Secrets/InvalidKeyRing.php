<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use InvalidArgumentException;

/** Misconfigured key-encryption keys. The application must refuse to start. */
final class InvalidKeyRing extends InvalidArgumentException
{
}
