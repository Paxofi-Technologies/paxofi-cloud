<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Tenant;

final readonly class TenantId
{
    public function __construct(public string $value)
    {
        if ($this->value === '') {
            throw new \InvalidArgumentException('Tenant ID must not be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
