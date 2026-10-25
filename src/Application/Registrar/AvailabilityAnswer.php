<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use PaxofiCloud\Domain\Registrar\CostAmount;

/**
 * A registrar's answer for one name in an availability check
 * (THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7): its result word, the one-year
 * register price if one came back, and the other operations that carried a
 * price. Only checked values reach this object; the registrar's text never does.
 */
final readonly class AvailabilityAnswer
{
    public const string WORD = '/^[a-z][a-z_]{0,31}$/';
    public const int MAX_OTHER_OPERATIONS = 5;

    /** @param list<string> $otherPriced sorted, unique operation words */
    public function __construct(
        public string $domain,
        public string $result,
        public ?CostAmount $register,
        public array $otherPriced,
    ) {
        if (preg_match(self::WORD, $result) !== 1) {
            throw new \InvalidArgumentException('Availability result must be a short word.');
        }
        if (count($otherPriced) > self::MAX_OTHER_OPERATIONS) {
            throw new \InvalidArgumentException('Too many priced operations.');
        }
        foreach ($otherPriced as $operation) {
            if (preg_match(self::WORD, $operation) !== 1) {
                throw new \InvalidArgumentException('Priced operations must be short words.');
            }
        }
    }
}
