<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Domain\Registrar\TldName;

/**
 * Staff settings for one extension (DX-3, DX-9, DX-10). Grace and redemption
 * are null until staff set them; the defaults then apply (DX-9). Fees are
 * USD cents. Featured order null means not featured.
 */
final readonly class ExtensionSettings
{
    public const array LABELS = ['none', 'hot', 'promo', 'sale', 'new'];
    public const int DEFAULT_GRACE_DAYS = 30;
    public const int DEFAULT_REDEMPTION_DAYS = 30;
    public const int MAX_DAYS = 90;
    public const int MAX_FEE_MINOR = 100_000_000;
    public const int MAX_FEATURED_ORDER = 999;
    public const array OVERRIDE_ACTIONS = ['register', 'renew', 'transfer'];

    /** @var array{usd: array<string, ?int>, ngn: array<string, ?int>} every action present; null = markup price */
    public array $overrides;
    public const int MAX_PRICE_MINOR = 10_000_000_000;

    /** @param array{usd?: array<string, ?int>, ngn?: array<string, ?int>} $overrides staff prices per action (DX-14); null uses the markup price */
    public function __construct(
        public TldName $tld,
        public ?string $pinnedRegistrar = null,
        public ?int $featuredOrder = null,
        public string $label = 'none',
        public ?int $graceDays = null,
        public ?int $graceFeeMinor = null,
        public ?int $redemptionDays = null,
        public ?int $redemptionFeeMinor = null,
        public ?string $category = null,
        array $overrides = [],
    ) {
        if ($category !== null && !in_array($category, ExtensionCategory::ALL, true)) {
            throw new \InvalidArgumentException('Unknown category.');
        }
        if (array_diff(array_keys($overrides), ['usd', 'ngn']) !== []) {
            throw new \InvalidArgumentException('Unknown price currency.');
        }
        $normal = ['usd' => [], 'ngn' => []];
        foreach (['usd', 'ngn'] as $currency) {
            foreach ($overrides[$currency] ?? [] as $action => $minor) {
                if (!in_array($action, self::OVERRIDE_ACTIONS, true) || ($minor !== null && ($minor < 1 || $minor > self::MAX_PRICE_MINOR))) {
                    throw new \InvalidArgumentException('Price override out of range.');
                }
            }
            foreach (self::OVERRIDE_ACTIONS as $action) {
                $normal[$currency][$action] = $overrides[$currency][$action] ?? null;
            }
        }
        $this->overrides = $normal;
        if ($pinnedRegistrar !== null && !in_array($pinnedRegistrar, RegistrarChoice::RANKING, true)) {
            throw new \InvalidArgumentException('Unknown registrar.');
        }
        if ($featuredOrder !== null && ($featuredOrder < 1 || $featuredOrder > self::MAX_FEATURED_ORDER)) {
            throw new \InvalidArgumentException('Featured order out of range.');
        }
        if (!in_array($label, self::LABELS, true)) {
            throw new \InvalidArgumentException('Unknown label.');
        }
        foreach ([$graceDays, $redemptionDays] as $days) {
            if ($days !== null && ($days < 0 || $days > self::MAX_DAYS)) {
                throw new \InvalidArgumentException('Days out of range.');
            }
        }
        foreach ([$graceFeeMinor, $redemptionFeeMinor] as $fee) {
            if ($fee !== null && ($fee < 0 || $fee > self::MAX_FEE_MINOR)) {
                throw new \InvalidArgumentException('Fee out of range.');
            }
        }
    }

    /** The override for one action and currency, or null for the markup price. */
    public function override(string $currency, string $action): ?int
    {
        return $this->overrides[$currency][$action] ?? null;
    }

    /** e.g. "r1500.n-.t-": audit codes stay short and character-safe. */
    private function overrideCode(string $currency): string
    {
        $parts = [];
        foreach (self::OVERRIDE_ACTIONS as $action) {
            $value = $this->override($currency, $action);
            $parts[] = $action[0] . ($value === null ? '-' : (string) $value);
        }

        return implode('.', $parts);
    }

    /**
     * The state after a change, as short audit codes (DX-12). The audit log is
     * append-only and hash-chained, so the previous event for the same
     * extension holds the state before.
     *
     * @return array<string, string|int>
     */
    public function auditValues(): array
    {
        return [
            'pin' => $this->pinnedRegistrar ?? 'none',
            'show' => ($this->featuredOrder ?? 0) . '.' . $this->label . '.' . ($this->category ?? 'auto'),
            'lifecycle' => ($this->graceDays ?? 'default') . '.' . ($this->graceFeeMinor ?? 0) . '.' . ($this->redemptionDays ?? 'default') . '.' . ($this->redemptionFeeMinor ?? 'registrar'),
            'usd' => $this->overrideCode('usd'),
            'ngn' => $this->overrideCode('ngn'),
        ];
    }
}
