<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainCostReport;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin Domain extensions page (THREAT-MODEL-DOMAIN-PRICING.md DX-2 to
 * DX-5, DX-9 to DX-13). Every extension any registrar offers, with the
 * registrar that would take new registrations, the other healthy offers and
 * the one-year selling prices in USD and NGN.
 *
 * `catalogue.view` reads. `catalogue.manage` changes an extension's pin,
 * featured order, label, grace and redemption. The pricing settings (markup,
 * minimum margin, rounding) also need `catalogue.manage` plus a fresh
 * authenticator code, at most 5 tries per 15 minutes (DX-11 as amended:
 * `settings.manage` is held only by Super Admin, which a sole founder cannot
 * be granted). Every change is audited with before and after (DX-12).
 */
final readonly class ExtensionsAdmin
{
    public const int PAGE_SIZE = 25;
    private const int STEP_UP_ATTEMPTS = 5;
    private const int STEP_UP_WINDOW = 900;

    /**
     * @param array<string, string> $registrars provider => environment, in ranking order
     * @param \Closure(string, string): bool $verifyCode staff ID, authenticator code
     */
    public function __construct(
        private array $registrars,
        private StaffAuthorizer $authorizer,
        private DomainCostReport $costs,
        private ExtensionSettingsStore $settings,
        private FxRateStore $rates,
        private RateLimiter $limiter,
        private \Closure $verifyCode,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{
     *     can_manage: bool, registrars: list<string>, labels: list<string>, categories: list<string>,
     *     pricing: ?array{markup_bp: int, min_margin_bp: int, usd_step: int, ngn_step: int},
     *     fx: ?array{fetched_at: string, source: string}, total: int, page: int, page_size: int,
     *     rows: list<array{
     *         tld: string, featured_order: ?int, label: string, pinned_registrar: ?string, pin_unavailable: bool,
     *         chosen_registrar: ?string, offers: list<array{registrar: string, register: int, renew: int, currency: string}>,
     *         cost: ?array<string, ?int>, usd: ?array<string, ?int>, ngn: ?array<string, ?int>, grace_days: int, grace_fee: int,
     *         redemption_days: int, redemption_fee: ?int, lifecycle_set: bool, category: ?string, category_shown: string,
     *         overrides: array{usd: array<string, ?int>, ngn: array<string, ?int>}, overrides_ignored: list<string>
     *     }>
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function overview(string $staffId, ClientContext $client, string $search = '', int $page = 1, bool $featuredOnly = false): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::CatalogueView, $client);
        $now = $this->clock->now();

        /** @var array<string, list<RegistrarOffer>> $offers */
        $offers = [];
        foreach ($this->registrars as $registrar => $environment) {
            $snapshot = $this->costs->latestSnapshot($registrar, $environment, true);
            if ($snapshot === null) {
                continue;
            }
            foreach ($this->costs->items($snapshot->id) as $cost) {
                $offers[$cost->tld->value][] = new RegistrarOffer($registrar, $cost, $snapshot->fetchedAt);
            }
        }
        $settings = $this->settings->all();
        $pricing = $this->settings->pricing();
        $rates = $this->rates->latestAccepted();

        $needle = strtolower(ltrim(trim($search), '.'));
        $tlds = array_keys($offers + $settings);
        $tlds = array_values(array_filter($tlds, static function (string|int $tld) use ($needle, $featuredOnly, $settings): bool {
            $tld = (string) $tld;

            return ($needle === '' || str_contains($tld, $needle)) && (!$featuredOnly || ($settings[$tld]->featuredOrder ?? null) !== null);
        }));
        // Featured first in their order, then alphabetical.
        usort($tlds, static fn (string|int $a, string|int $b): int => [($settings[(string) $a]->featuredOrder ?? PHP_INT_MAX), (string) $a]
            <=> [($settings[(string) $b]->featuredOrder ?? PHP_INT_MAX), (string) $b]);

        $total = count($tlds);
        $page = max(1, min($page, max(1, (int) ceil($total / self::PAGE_SIZE))));
        $rows = [];
        foreach (array_slice($tlds, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as $tld) {
            $tld = (string) $tld;
            $own = $settings[$tld] ?? new ExtensionSettings(new TldName($tld));
            $choice = RegistrarChoice::choose($offers[$tld] ?? [], $own->pinnedRegistrar, $now);
            $prices = $choice->chosen !== null && $pricing !== null && $rates !== null
                ? ExtensionPrices::for($choice->chosen->cost, $pricing, $rates->rates, $own)
                : null;
            $rows[] = [
                'tld' => $tld,
                'featured_order' => $own->featuredOrder,
                'label' => $own->label,
                'pinned_registrar' => $own->pinnedRegistrar,
                'pin_unavailable' => $choice->pinUnavailable,
                'chosen_registrar' => $choice->chosen?->registrar,
                'offers' => array_map(static fn (RegistrarOffer $o): array => [
                    'registrar' => $o->registrar,
                    'register' => $o->cost->register->minor,
                    'renew' => $o->cost->renew->minor,
                    'currency' => $o->cost->register->currency->value,
                ], $choice->healthy),
                'cost' => $choice->chosen === null ? null : self::costs($choice->chosen),
                'usd' => $prices?->usd,
                'ngn' => $prices?->ngn,
                'grace_days' => $own->graceDays ?? ExtensionSettings::DEFAULT_GRACE_DAYS,
                'grace_fee' => $own->graceFeeMinor ?? 0,
                'redemption_days' => $own->redemptionDays ?? ExtensionSettings::DEFAULT_REDEMPTION_DAYS,
                'redemption_fee' => $own->redemptionFeeMinor,
                'lifecycle_set' => $own->graceDays !== null || $own->redemptionDays !== null,
                'category' => $own->category,
                'category_shown' => ExtensionCategory::of($tld, $own->category),
                'overrides' => [
                    'usd' => array_map(static fn (string $a): ?int => $own->override('usd', $a), array_combine(ExtensionSettings::OVERRIDE_ACTIONS, ExtensionSettings::OVERRIDE_ACTIONS)),
                    'ngn' => array_map(static fn (string $a): ?int => $own->override('ngn', $a), array_combine(ExtensionSettings::OVERRIDE_ACTIONS, ExtensionSettings::OVERRIDE_ACTIONS)),
                ],
                'overrides_ignored' => $prices === null ? [] : $prices->ignored,
            ];
        }

        return [
            'can_manage' => $principal->can(Permission::CatalogueManage),
            'registrars' => array_keys($this->registrars),
            'labels' => ExtensionSettings::LABELS,
            'categories' => ExtensionCategory::ALL,
            'pricing' => $pricing === null ? null : [
                'markup_bp' => $pricing->markup->basisPoints,
                'min_margin_bp' => $pricing->minimumMargin->value,
                'usd_step' => $pricing->usdStep->minorUnits,
                'ngn_step' => $pricing->ngnStep->minorUnits,
            ],
            'fx' => $rates === null ? null : ['fetched_at' => $rates->fetchedAt->format(\DATE_ATOM), 'source' => $rates->rates->source],
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<array-key, mixed> $input tld, pinned_registrar, featured_order, label, grace_days, grace_fee, redemption_days, redemption_fee
     * @throws ExtensionChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function saveExtension(string $staffId, ClientContext $client, array $input): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $allowed = ['tld', 'pinned_registrar', 'featured_order', 'label', 'grace_days', 'grace_fee', 'redemption_days', 'redemption_fee', 'category', 'prices'];
        if (array_diff(array_keys($input), $allowed) !== [] || !is_string($input['tld'] ?? null)) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }
        try {
            $next = new ExtensionSettings(
                new TldName($input['tld']),
                self::nullableString($input['pinned_registrar'] ?? null),
                self::nullableInt($input['featured_order'] ?? null),
                is_string($input['label'] ?? null) ? $input['label'] : 'none',
                self::nullableInt($input['grace_days'] ?? null),
                self::nullableInt($input['grace_fee'] ?? null),
                self::nullableInt($input['redemption_days'] ?? null),
                self::nullableInt($input['redemption_fee'] ?? null),
                self::nullableString($input['category'] ?? null),
                self::overrides($input['prices'] ?? null),
            );
        } catch (\InvalidArgumentException) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }
        $this->refuseBelowFloor($next);
        $this->settings->save($next, $staffId, $this->clock->now());
        $this->audit->record(AuditEventType::DOMAIN_EXTENSION_CHANGED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'tld' => $next->tld->value,
        ] + $next->auditValues());
    }

    /**
     * @param array<array-key, mixed> $input markup_bp, min_margin_bp, usd_step, ngn_step, code
     * @throws ExtensionChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function savePricing(string $staffId, ClientContext $client, array $input): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $retry = $this->limiter->hit('staff:domain_pricing:' . $staffId, self::STEP_UP_ATTEMPTS, self::STEP_UP_WINDOW);
        if ($retry > 0) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::THROTTLED, $retry);
        }
        $code = $input['code'] ?? null;
        if (!is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || !($this->verifyCode)($staffId, $code)) {
            $this->audit->record(AuditEventType::DOMAIN_PRICING_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: ['actor' => $staffId, 'reason' => ExtensionChangeRefused::STEP_UP]);

            throw new ExtensionChangeRefused(ExtensionChangeRefused::STEP_UP);
        }
        if (array_diff(array_keys($input), ['markup_bp', 'min_margin_bp', 'usd_step', 'ngn_step', 'code']) !== []) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }
        try {
            $next = new DomainPricingSettings(
                new Markup(self::requiredInt($input['markup_bp'] ?? null)),
                new BasisPoints(self::requiredInt($input['min_margin_bp'] ?? null)),
                new RoundingStep(self::requiredInt($input['usd_step'] ?? null)),
                new RoundingStep(self::requiredInt($input['ngn_step'] ?? null)),
            );
        } catch (\InvalidArgumentException) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }
        $this->settings->savePricing($next, $staffId, $this->clock->now());
        // The full state after the change; the previous event of this type holds the state before (DX-12).
        $this->audit->record(AuditEventType::DOMAIN_PRICING_CHANGED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'markup_bp' => $next->markup->basisPoints,
            'min_margin_bp' => $next->minimumMargin->value,
            'usd_step' => $next->usdStep->minorUnits,
            'ngn_step' => $next->ngnStep->minorUnits,
        ]);
    }

    /**
     * The chosen registrar's one-year cost per action, in USD cents, so staff
     * see the base price before any pricing settings exist. Admin only (DX-13).
     *
     * @return array<string, ?int>
     */
    private static function costs(RegistrarOffer $offer): array
    {
        $usd = static fn (?CostAmount $a): ?int => $a !== null && $a->currency->value === 'USD' ? $a->minor : null;

        return [
            'register' => $usd($offer->cost->register),
            'renew' => $usd($offer->cost->renew),
            'transfer' => $usd($offer->cost->transfer),
            'restore' => $usd($offer->cost->restore),
        ];
    }

    /**
     * DX-14: a staff price must be at or above today's floor (cost plus the
     * minimum margin) for the registrar that would take the order. Checked
     * again at every quote, so a later cost rise never sells at a loss.
     *
     * @throws ExtensionChangeRefused
     */
    private function refuseBelowFloor(ExtensionSettings $next): void
    {
        $hasOverride = false;
        foreach (['usd', 'ngn'] as $currency) {
            foreach (ExtensionSettings::OVERRIDE_ACTIONS as $action) {
                $hasOverride = $hasOverride || $next->override($currency, $action) !== null;
            }
        }
        if (!$hasOverride) {
            return;
        }
        $pricing = $this->settings->pricing();
        $rates = $this->rates->latestAccepted();
        $offers = [];
        foreach ($this->registrars as $registrar => $environment) {
            $snapshot = $this->costs->latestSnapshot($registrar, $environment, true);
            if ($snapshot === null) {
                continue;
            }
            foreach ($this->costs->items($snapshot->id) as $cost) {
                if ($cost->tld->value === $next->tld->value) {
                    $offers[] = new RegistrarOffer($registrar, $cost, $snapshot->fetchedAt);
                }
            }
        }
        $choice = RegistrarChoice::choose($offers, $next->pinnedRegistrar, $this->clock->now());
        if ($pricing === null || $rates === null || $choice->chosen === null) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::BELOW_FLOOR);
        }
        $prices = ExtensionPrices::for($choice->chosen->cost, $pricing, $rates->rates, $next);
        if ($prices->ignored !== []) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::BELOW_FLOOR);
        }
    }

    /** @return array{usd: array<string, ?int>, ngn: array<string, ?int>} */
    private static function overrides(mixed $value): array
    {
        $out = ['usd' => [], 'ngn' => []];
        if ($value === null) {
            return $out;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['usd', 'ngn']) !== []) {
            throw new \InvalidArgumentException('Prices must be {usd, ngn}.');
        }
        foreach (['usd', 'ngn'] as $currency) {
            $set = $value[$currency] ?? [];
            if (!is_array($set) || array_diff(array_keys($set), ExtensionSettings::OVERRIDE_ACTIONS) !== []) {
                throw new \InvalidArgumentException('Unknown price action.');
            }
            foreach (ExtensionSettings::OVERRIDE_ACTIONS as $action) {
                $out[$currency][$action] = self::nullableInt($set[$action] ?? null);
            }
        }

        return $out;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Expected a whole number.');
        }

        return $value;
    }

    private static function requiredInt(mixed $value): int
    {
        return self::nullableInt($value) ?? throw new \InvalidArgumentException('Required.');
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected text.');
        }

        return $value;
    }
}
