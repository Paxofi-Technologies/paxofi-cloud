<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Extensions;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL domain pricing and extension settings (migration 0020). */
final readonly class MysqlExtensionSettingsStore implements ExtensionSettingsStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function pricing(): ?DomainPricingSettings
    {
        $row = $this->repository->fetchAll('SELECT markup_bp, min_margin_bp, usd_step_minor, ngn_step_minor FROM domain_pricing_settings WHERE id = 1')[0] ?? null;

        return $row === null ? null : new DomainPricingSettings(
            new Markup(Row::int($row, 'markup_bp')),
            new BasisPoints(Row::int($row, 'min_margin_bp')),
            new RoundingStep(Row::int($row, 'usd_step_minor')),
            new RoundingStep(Row::int($row, 'ngn_step_minor')),
        );
    }

    public function savePricing(DomainPricingSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO domain_pricing_settings (id, markup_bp, min_margin_bp, usd_step_minor, ngn_step_minor, updated_by, updated_at)
             VALUES (1, :markup, :margin, :usd, :ngn, :staff, :at)
             ON DUPLICATE KEY UPDATE markup_bp = VALUES(markup_bp), min_margin_bp = VALUES(min_margin_bp), usd_step_minor = VALUES(usd_step_minor),
                 ngn_step_minor = VALUES(ngn_step_minor), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [
                'markup' => $settings->markup->basisPoints,
                'margin' => $settings->minimumMargin->value,
                'usd' => $settings->usdStep->minorUnits,
                'ngn' => $settings->ngnStep->minorUnits,
                'staff' => $staffId,
                'at' => self::utc($at),
            ],
        );
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->repository->fetchAll(
            'SELECT tld, pinned_registrar, featured_order, label, grace_days, grace_fee_minor, redemption_days, redemption_fee_minor FROM domain_extension_settings',
        ) as $row) {
            $tld = Row::string($row, 'tld');
            $result[$tld] = new ExtensionSettings(
                new TldName($tld),
                self::nullableString($row, 'pinned_registrar'),
                self::nullableInt($row, 'featured_order'),
                Row::string($row, 'label'),
                self::nullableInt($row, 'grace_days'),
                self::nullableInt($row, 'grace_fee_minor'),
                self::nullableInt($row, 'redemption_days'),
                self::nullableInt($row, 'redemption_fee_minor'),
            );
        }

        return $result;
    }

    public function save(ExtensionSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO domain_extension_settings (tld, pinned_registrar, featured_order, label, grace_days, grace_fee_minor, redemption_days, redemption_fee_minor, updated_by, updated_at)
             VALUES (:tld, :pin, :featured, :label, :grace, :grace_fee, :redemption, :redemption_fee, :staff, :at)
             ON DUPLICATE KEY UPDATE pinned_registrar = VALUES(pinned_registrar), featured_order = VALUES(featured_order), label = VALUES(label),
                 grace_days = VALUES(grace_days), grace_fee_minor = VALUES(grace_fee_minor), redemption_days = VALUES(redemption_days),
                 redemption_fee_minor = VALUES(redemption_fee_minor), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [
                'tld' => $settings->tld->value,
                'pin' => $settings->pinnedRegistrar,
                'featured' => $settings->featuredOrder,
                'label' => $settings->label,
                'grace' => $settings->graceDays,
                'grace_fee' => $settings->graceFeeMinor,
                'redemption' => $settings->redemptionDays,
                'redemption_fee' => $settings->redemptionFeeMinor,
                'staff' => $staffId,
                'at' => self::utc($at),
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : Row::int($row, $column);
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : Row::string($row, $column);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }
}
