<?php

declare(strict_types=1);

use PaxofiCloud\Infrastructure\Resilience\TransientFailure;

return [
    'retry' => [
        // Bounded by default (PCF invariant: no unbounded retries).
        'attempts' => 3,
        'initial_delay_ms' => 200,
        'maximum_delay_ms' => 2_000,
        'jitter_ratio' => 0.2,
        // Only failures explicitly classified as transient are retried.
        'retryable_exceptions' => [TransientFailure::class],
        'maximum_elapsed_ms' => 10_000,
    ],
];
