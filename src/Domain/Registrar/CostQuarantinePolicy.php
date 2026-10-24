<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * Decides whether a new cost list is plausible enough to use (commerce threat
 * model CD-13, C-25; THREAT-MODEL-DOMAIN-REGISTRAR.md DR-8). A list is held
 * for staff review, changing nothing, when:
 *
 * - it is empty;
 * - more than 20% of the previously accepted extensions are missing; or
 * - more than 20% of the extensions in both lists changed register or renew
 *   cost by more than 50% (a change of currency counts as such a change).
 *
 * The first list for a provider has nothing to compare with and is accepted
 * unless empty. Thresholds are percentages so they stay integer arithmetic.
 */
final readonly class CostQuarantinePolicy
{
    public function __construct(
        private int $maxMissingPercent = 20,
        private int $maxJumpedPercent = 20,
        private int $jumpPercent = 50,
    ) {
        foreach ([$maxMissingPercent, $maxJumpedPercent, $jumpPercent] as $percent) {
            if ($percent < 1 || $percent > 100) {
                throw new \InvalidArgumentException('Quarantine thresholds are percentages from 1 to 100.');
            }
        }
    }

    /**
     * @param array<string, TldCost>|null $previous the last accepted list, keyed by extension
     * @return 'empty'|'items_disappeared'|'costs_jumped'|null null when the list may be used
     */
    public function assess(?array $previous, TldCostList $next): ?string
    {
        if ($next->items === []) {
            return 'empty';
        }
        if ($previous === null || $previous === []) {
            return null;
        }

        $missing = count(array_diff_key($previous, $next->items));
        if ($missing * 100 > count($previous) * $this->maxMissingPercent) {
            return 'items_disappeared';
        }

        $common = array_intersect_key($next->items, $previous);
        $jumped = 0;
        foreach ($common as $tld => $cost) {
            $before = $previous[$tld];
            if ($this->jumped($before->register, $cost->register) || $this->jumped($before->renew, $cost->renew)) {
                $jumped++;
            }
        }

        return $common !== [] && $jumped * 100 > count($common) * $this->maxJumpedPercent ? 'costs_jumped' : null;
    }

    private function jumped(CostAmount $before, CostAmount $after): bool
    {
        if ($before->currency !== $after->currency) {
            return true;
        }

        return abs($after->minor - $before->minor) * 100 > $before->minor * $this->jumpPercent;
    }
}
