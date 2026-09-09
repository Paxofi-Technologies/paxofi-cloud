<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($currency === '' || strlen($currency) !== 3 || strtoupper($currency) !== $currency) {
            throw new \InvalidArgumentException('Currency must be a three-letter uppercase ISO-style code.');
        }
    }
}
