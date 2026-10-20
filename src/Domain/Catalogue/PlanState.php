<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

/**
 * Plan lifecycle (CAT-002). Only ACTIVE plans can be added to a cart. LIMITED,
 * DEPRECATED plans keep renewing existing services; RETIRED plans do not.
 */
enum PlanState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Limited = 'limited';
    case Deprecated = 'deprecated';
    case Retired = 'retired';

    public function acceptsNewOrders(): bool
    {
        return $this === self::Active;
    }

    public function renews(): bool
    {
        return in_array($this, [self::Active, self::Limited, self::Deprecated], true);
    }

    /** Allowed moves; anything else is refused (threat model C-07, C-10). */
    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Active],
            self::Active => [self::Limited, self::Deprecated],
            self::Limited => [self::Active, self::Deprecated],
            self::Deprecated => [self::Retired],
            self::Retired => [],
        }, true);
    }
}
