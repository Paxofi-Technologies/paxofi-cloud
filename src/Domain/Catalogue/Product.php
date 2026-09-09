<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

final readonly class Product
{
    public function __construct(
        public ProductId $id,
        public string $name,
        public bool $available,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Product name cannot be empty.');
        }
    }
}
