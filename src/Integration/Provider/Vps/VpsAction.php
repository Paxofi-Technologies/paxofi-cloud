<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/** An asynchronous provider operation that a provisioning job polls until it finishes. */
final class VpsAction
{
    public function __construct(
        public readonly string $actionId,
        public readonly VpsActionStatus $status,
        public readonly ?string $errorCode = null,
    ) {
        if ($actionId === '') {
            throw new \InvalidArgumentException('Action ID must not be empty.');
        }
    }

    public function isFinished(): bool
    {
        return $this->status !== VpsActionStatus::Running;
    }
}
