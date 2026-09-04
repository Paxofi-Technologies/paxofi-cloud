<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

final readonly class ProductId
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Product ID cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
