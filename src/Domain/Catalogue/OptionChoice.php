<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

final readonly class OptionChoice
{
    /** @param list<DatedPrice> $prices modifier for picking this choice; empty = no extra charge */
    public function __construct(public string $code, public string $label, public array $prices = [])
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $code) !== 1) {
            throw new CatalogueRuleViolation('invalid_code', 'choice');
        }
    }
}
