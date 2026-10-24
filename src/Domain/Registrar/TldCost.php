<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * What one extension costs us for one year at a registrar (CD-13). Register
 * and renew are required; transfer and restore are optional because not every
 * extension supports them. All costs of one extension share one currency.
 */
final readonly class TldCost
{
    public function __construct(
        public TldName $tld,
        public CostAmount $register,
        public CostAmount $renew,
        public ?CostAmount $transfer,
        public ?CostAmount $restore,
        public int $minYears,
        public int $maxYears,
        public string $providerStatus,
    ) {
        foreach ([$renew, $transfer, $restore] as $other) {
            if ($other !== null && $other->currency !== $register->currency) {
                throw new \InvalidArgumentException('All costs of one extension must be in one currency.');
            }
        }
        if ($minYears < 1 || $maxYears > 10 || $minYears > $maxYears) {
            throw new \InvalidArgumentException('Registration periods must be whole years between 1 and 10.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $providerStatus) !== 1) {
            throw new \InvalidArgumentException('Provider status must be a short code.');
        }
    }
}
