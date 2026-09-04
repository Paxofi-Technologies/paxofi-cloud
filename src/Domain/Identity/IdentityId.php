<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Identity;

final readonly class IdentityId
{
    public function __construct(public string $value)
    {
        if ($this->value === '') {
            throw new \InvalidArgumentException('Identity ID must not be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
