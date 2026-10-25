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
    public const array LABELS = ['none', 'hot', 'new', 'sale'];
    public const int DEFAULT_GRACE_DAYS = 30;
    public const int DEFAULT_REDEMPTION_DAYS = 30;
    public const int MAX_DAYS = 90;
    public const int MAX_FEE_MINOR = 100_000_000;
    public const int MAX_FEATURED_ORDER = 999;

    public function __construct(
        public TldName $tld,
        public ?string $pinnedRegistrar = null,
        public ?int $featuredOrder = null,
        public string $label = 'none',
        public ?int $graceDays = null,
        public ?int $graceFeeMinor = null,
        public ?int $redemptionDays = null,
        public ?int $redemptionFeeMinor = null,
    ) {
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
            'featured' => $this->featuredOrder ?? 0,
            'label' => $this->label,
            'grace' => ($this->graceDays ?? 'default') . '.' . ($this->graceFeeMinor ?? 0),
            'redemption' => ($this->redemptionDays ?? 'default') . '.' . ($this->redemptionFeeMinor ?? 'registrar'),
        ];
    }
}
