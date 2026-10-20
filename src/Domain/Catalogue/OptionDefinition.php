<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

/**
 * A typed plan option (CAT-004): a choice from a list (VPS location, OS
 * image), a whole number in a range (extra IPs), or on/off (backups). Values
 * from a cart request are validated here before they are priced or stored
 * (threat model C-08); errors name the option.
 */
final readonly class OptionDefinition
{
    /**
     * @param list<OptionChoice> $choices for enum options
     * @param list<DatedPrice> $unitPrices per unit (integer) or when on (boolean); empty = no extra charge
     */
    public function __construct(
        public string $code,
        public string $label,
        public OptionKind $kind,
        public bool $required,
        public ?int $min = null,
        public ?int $max = null,
        public array $choices = [],
        public array $unitPrices = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code) !== 1) {
            throw new CatalogueRuleViolation('invalid_code', 'option');
        }
        if ($kind === OptionKind::Integer && ($min === null || $max === null || $min < 0 || $max < $min || $max > 1000)) {
            throw new CatalogueRuleViolation('invalid_range', $code);
        }
        if ($kind === OptionKind::Choice && $choices === []) {
            throw new CatalogueRuleViolation('no_choices', $code);
        }
    }

    /**
     * The normalised value, or null when an optional option is left out.
     *
     * @throws CatalogueRuleViolation
     */
    public function validate(mixed $value): string|int|bool|null
    {
        if ($value === null) {
            if ($this->required) {
                throw new CatalogueRuleViolation('required', $this->code);
            }

            return null;
        }

        return match ($this->kind) {
            OptionKind::Choice => $this->choiceValue($value),
            OptionKind::Integer => $this->integerValue($value),
            OptionKind::Boolean => is_bool($value) ? $value : throw new CatalogueRuleViolation('invalid_value', $this->code),
        };
    }

    public function choice(string $code): ?OptionChoice
    {
        foreach ($this->choices as $choice) {
            if ($choice->code === $code) {
                return $choice;
            }
        }

        return null;
    }

    private function choiceValue(mixed $value): string
    {
        if (!is_string($value) || $this->choice($value) === null) {
            throw new CatalogueRuleViolation('invalid_value', $this->code);
        }

        return $value;
    }

    private function integerValue(mixed $value): int
    {
        if (!is_int($value) || $value < (int) $this->min || $value > (int) $this->max) {
            throw new CatalogueRuleViolation('out_of_range', $this->code);
        }

        return $value;
    }
}
