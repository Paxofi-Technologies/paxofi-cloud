<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/** One finished Spaceship price check (SP-7): done with its items, or failed with a fixed reason. */
final readonly class PriceCheckRun
{
    public const array TRIGGERS = ['manual', 'cli'];

    /** @param list<PriceCheckItem> $items */
    public function __construct(
        public string $id,
        public string $trigger,
        public \DateTimeImmutable $checkedAt,
        public ?string $failure,
        public int $asked,
        public array $items,
    ) {
        if (!in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException('Unknown price check trigger.');
        }
        if ($failure !== null && !in_array($failure, RegistrarUnavailable::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown price check failure.');
        }
        if ($failure !== null && $items !== []) {
            throw new \InvalidArgumentException('A failed check has no items.');
        }
        if ($asked < 0 || $asked > SpaceshipPriceCheck::PROBE_TLDS_COUNT || count($items) > $asked) {
            throw new \InvalidArgumentException('Price check counts out of range.');
        }
    }

    public function answered(): int
    {
        return count(array_filter($this->items, static fn (PriceCheckItem $item): bool => $item->result !== 'missing'));
    }

    public function priced(): int
    {
        return count(array_filter($this->items, static fn (PriceCheckItem $item): bool => $item->register !== null));
    }
}
