<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

use RuntimeException;

/** Network-level failure before an HTTP response was received (timeout, DNS, TLS, reset). */
final class TransportFailure extends RuntimeException
{
}
