<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Domain\Pricing\CostCurrency;

/**
 * Which registrar takes new registrations of an extension
 * (THREAT-MODEL-DOMAIN-PRICING.md DX-2, DX-3). An offer is healthy when its
 * snapshot is under 72 hours old and in USD (every registrar bills USD
 * today; another currency is not compared until it is converted, DX-1).
 * The cheapest one-year register cost wins; ties go to the CEO ranking. A
 * pin wins when the pinned offer is healthy; otherwise the cheapest is used
 * and the pin is flagged unavailable. Existing domains keep their registrar.
 */
final readonly class RegistrarChoice
{
    public const array RANKING = ['domainnameapi', 'namecheap', 'dynadot', 'spaceship'];
    public const int HEALTHY_HOURS = 72;

    /** @param list<RegistrarOffer> $healthy cheapest first */
    private function __construct(
        public ?RegistrarOffer $chosen,
        public bool $pinUnavailable,
        public array $healthy,
    ) {
    }

    /** @param list<RegistrarOffer> $offers */
    public static function choose(array $offers, ?string $pin, \DateTimeImmutable $now): self
    {
        $cutoff = $now->modify('-' . self::HEALTHY_HOURS . ' hours');
        $healthy = array_values(array_filter(
            $offers,
            static fn (RegistrarOffer $o): bool => $o->fetchedAt > $cutoff && $o->cost->register->currency === CostCurrency::USD && in_array($o->registrar, self::RANKING, true),
        ));
        usort($healthy, static fn (RegistrarOffer $a, RegistrarOffer $b): int => [$a->cost->register->minor, array_search($a->registrar, self::RANKING, true)]
            <=> [$b->cost->register->minor, array_search($b->registrar, self::RANKING, true)]);

        if ($pin !== null) {
            foreach ($healthy as $offer) {
                if ($offer->registrar === $pin) {
                    return new self($offer, false, $healthy);
                }
            }

            return new self($healthy[0] ?? null, true, $healthy);
        }

        return new self($healthy[0] ?? null, false, $healthy);
    }
}
