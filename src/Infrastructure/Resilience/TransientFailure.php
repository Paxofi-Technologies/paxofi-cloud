<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Resilience;

use RuntimeException;

/**
 * Marks a failure as safe to retry (timeouts, 429/503 from a provider, lock
 * contention). The default retry policy retries only this type, so anything
 * not explicitly classified as transient fails fast.
 */
final class TransientFailure extends RuntimeException
{
}
