<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * A registrar's authoritative answer for one domain (THREAT-MODEL-DOMAIN-SEARCH.md
 * DS-13): available, taken, unavailable (reserved or blocked by the registry)
 * or unknown. A premium name carries the registrar's one-year cost when it
 * gave one in a currency we can price.
 */
final readonly class AvailabilityAnswer
{
    public const array STATUSES = ['available', 'taken', 'unavailable', 'unknown'];

    public function __construct(public string $status, public bool $premium = false, public ?CostAmount $premiumCost = null)
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown availability status.');
        }
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }
}
