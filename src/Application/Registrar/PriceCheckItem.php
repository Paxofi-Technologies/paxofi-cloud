<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * What one extension's test name got back in a Spaceship price check (SP-7).
 * The test name itself is not kept. `missing` means the name was not in the answer.
 */
final readonly class PriceCheckItem
{
    /** @param list<string> $otherPriced */
    public function __construct(
        public TldName $tld,
        public string $result,
        public ?CostAmount $register,
        public array $otherPriced,
    ) {
        if (preg_match(AvailabilityAnswer::WORD, $result) !== 1 || count($otherPriced) > AvailabilityAnswer::MAX_OTHER_OPERATIONS) {
            throw new \InvalidArgumentException('Price check items hold short words only.');
        }
    }
}
