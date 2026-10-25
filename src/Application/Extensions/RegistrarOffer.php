<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Domain\Registrar\TldCost;

/** One registrar's latest accepted cost for an extension (DX-2). */
final readonly class RegistrarOffer
{
    public function __construct(
        public string $registrar,
        public TldCost $cost,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
