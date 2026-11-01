<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;
use PaxofiCloud\Application\Domains\NameIdeas;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * Catalogue settings export and import (THREAT-MODEL-DOMAIN-PRICING.md
 * DX-15), so a staging rebuild or a move to production does not lose staff
 * work: the pricing settings, every extension's settings (pin, featured
 * order, label, category, grace, redemption, price overrides), the domain
 * search settings and each extension's lookup settings. No costs, rates,
 * credentials or customer data are in the file.
 *
 * Both need `catalogue.manage`: the file holds our margins. Export is
 * audited. Import also needs a fresh authenticator code (5 tries per 15
 * minutes) because it changes the pricing settings and may set WHOIS
 * servers. The whole file is checked before anything is saved; extensions
 * then go through the normal extension save (same rules, floor check and
 * audit), and an extension it refuses is reported back, not fatal.
 */
final readonly class CatalogueSettingsTransfer
{
    public const string FORMAT = 'paxoficloud.catalogue-settings';
    public const int VERSION = 1;
    public const int MAX_EXTENSIONS = 2000;
    private const int STEP_UP_ATTEMPTS = 5;
    private const int STEP_UP_WINDOW = 900;

    /** @param \Closure(string, string): bool $verifyCode staff ID, authenticator code */
    public function __construct(
        private StaffAuthorizer $authorizer,
        private ExtensionSettingsStore $extensions,
        private DomainSearchSettingsStore $search,
        private ExtensionsAdmin $extensionsAdmin,
        private RateLimiter $limiter,
        private \Closure $verifyCode,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function export(string $staffId, ClientContext $client): array
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $pricing = $this->extensions->pricing();
        $extensions = [];
        foreach ($this->extensions->all() as $settings) {
            $extensions[] = [
                'tld' => $settings->tld->value,
                'pinned_registrar' => $settings->pinnedRegistrar,
                'featured_order' => $settings->featuredOrder,
                'label' => $settings->label,
                'grace_days' => $settings->graceDays,
                'grace_fee' => $settings->graceFeeMinor,
                'redemption_days' => $settings->redemptionDays,
                'redemption_fee' => $settings->redemptionFeeMinor,
                'category' => $settings->category,
                'prices' => $settings->overrides,
            ];
        }
        usort($extensions, static fn (array $a, array $b): int => strcmp($a['tld'], $b['tld']));
        $search = $this->search->settings();
        $lookups = [];
        foreach ($this->search->lookups() as $lookup) {
            $lookups[] = ['tld' => $lookup->tld, 'mode' => $lookup->mode, 'whois_server' => $lookup->whoisServer, 'whois_phrase' => $lookup->whoisPhrase];
        }
        usort($lookups, static fn (array $a, array $b): int => strcmp($a['tld'], $b['tld']));
        $this->audit->record(AuditEventType::CATALOGUE_SETTINGS_EXPORTED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'extensions' => count($extensions),
            'lookups' => count($lookups),
            'pricing' => $pricing !== null,
        ]);

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'pricing' => $pricing === null ? null : [
                'markup_bp' => $pricing->markup->basisPoints,
                'min_margin_bp' => $pricing->minimumMargin->value,
                'usd_step' => $pricing->usdStep->minorUnits,
                'ngn_step' => $pricing->ngnStep->minorUnits,
            ],
            'extensions' => $extensions,
            'search' => ['search_tlds' => $search->searchTlds, 'max_ideas' => $search->maxIdeas, 'prefixes' => $search->prefixes, 'suffixes' => $search->suffixes],
            'lookups' => $lookups,
        ];
    }

    /**
     * @param array<array-key, mixed> $input {settings: <an export>, code}
     * @return array{pricing: bool, extensions_saved: int, extensions_skipped: list<array{tld: string, reason: string}>, search: bool, lookups_saved: int}
     * @throws ExtensionChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function import(string $staffId, ClientContext $client, array $input): array
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $retry = $this->limiter->hit('staff:catalogue_import:' . $staffId, self::STEP_UP_ATTEMPTS, self::STEP_UP_WINDOW);
        if ($retry > 0) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::THROTTLED, $retry);
        }
        $code = $input['code'] ?? null;
        if (!is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || !($this->verifyCode)($staffId, $code)) {
            $this->audit->record(AuditEventType::CATALOGUE_SETTINGS_IMPORT_REFUSED, AuditEvent::FAILURE, $client, details: ['actor' => $staffId, 'reason' => ExtensionChangeRefused::STEP_UP]);

            throw new ExtensionChangeRefused(ExtensionChangeRefused::STEP_UP);
        }
        $file = $input['settings'] ?? null;
        if (array_diff(array_keys($input), ['settings', 'code']) !== [] || !is_array($file)) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }

        // Check the whole file first: nothing is saved from a file with any invalid part.
        [$pricing, $extensions, $search, $lookups] = self::parse($file);

        $now = $this->clock->now();
        if ($pricing !== null) {
            $this->extensions->savePricing($pricing, $staffId, $now);
        }
        $saved = 0;
        $skipped = [];
        foreach ($extensions as $extension) {
            try {
                $this->extensionsAdmin->saveExtension($staffId, $client, $extension);
                $saved++;
            } catch (ExtensionChangeRefused $e) {
                $tld = $extension['tld'] ?? '';
                $skipped[] = ['tld' => is_string($tld) ? $tld : '', 'reason' => $e->reason];
            }
        }
        if ($search !== null) {
            $this->search->saveSettings($search, $staffId, $now);
        }
        foreach ($lookups as $lookup) {
            $this->search->saveLookup($lookup, $staffId, $now);
        }
        $this->audit->record(AuditEventType::CATALOGUE_SETTINGS_IMPORTED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'pricing' => $pricing !== null,
            'extensions_saved' => $saved,
            'extensions_skipped' => count($skipped),
            'search' => $search !== null,
            'lookups' => count($lookups),
        ]);

        return ['pricing' => $pricing !== null, 'extensions_saved' => $saved, 'extensions_skipped' => $skipped, 'search' => $search !== null, 'lookups_saved' => count($lookups)];
    }

    /**
     * @param array<array-key, mixed> $file
     * @return array{0: ?DomainPricingSettings, 1: list<array<string, mixed>>, 2: ?DomainSearchSettings, 3: list<LookupSettings>}
     * @throws ExtensionChangeRefused
     */
    private static function parse(array $file): array
    {
        if (($file['format'] ?? null) !== self::FORMAT || ($file['version'] ?? null) !== self::VERSION
            || array_diff(array_keys($file), ['format', 'version', 'exported_at', 'pricing', 'extensions', 'search', 'lookups']) !== []) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }
        try {
            $pricing = null;
            $p = $file['pricing'] ?? null;
            if ($p !== null) {
                if (!is_array($p) || array_diff(array_keys($p), ['markup_bp', 'min_margin_bp', 'usd_step', 'ngn_step']) !== []) {
                    throw new \InvalidArgumentException('pricing');
                }
                $pricing = new DomainPricingSettings(
                    new Markup(self::int($p['markup_bp'] ?? null)),
                    new BasisPoints(self::int($p['min_margin_bp'] ?? null)),
                    new RoundingStep(self::int($p['usd_step'] ?? null)),
                    new RoundingStep(self::int($p['ngn_step'] ?? null)),
                );
            }

            $extensions = [];
            $rows = $file['extensions'] ?? [];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_EXTENSIONS) {
                throw new \InvalidArgumentException('extensions');
            }
            $allowed = ['tld', 'pinned_registrar', 'featured_order', 'label', 'grace_days', 'grace_fee', 'redemption_days', 'redemption_fee', 'category', 'prices'];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['tld'] ?? null) || array_diff(array_keys($row), $allowed) !== []) {
                    throw new \InvalidArgumentException('extension');
                }
                /** @var array<string, mixed> $row */
                $extensions[] = $row;
            }

            $search = null;
            $s = $file['search'] ?? null;
            if ($s !== null) {
                if (!is_array($s) || array_diff(array_keys($s), ['search_tlds', 'max_ideas', 'prefixes', 'suffixes']) !== []) {
                    throw new \InvalidArgumentException('search');
                }
                $tlds = $s['search_tlds'] ?? null;
                $search = new DomainSearchSettings(
                    $tlds === null ? null : self::strings($tlds),
                    self::int($s['max_ideas'] ?? null),
                    NameIdeas::clean(self::strings($s['prefixes'] ?? [])),
                    NameIdeas::clean(self::strings($s['suffixes'] ?? [])),
                );
            }

            $lookups = [];
            $l = $file['lookups'] ?? [];
            if (!is_array($l) || !array_is_list($l) || count($l) > self::MAX_EXTENSIONS) {
                throw new \InvalidArgumentException('lookups');
            }
            foreach ($l as $row) {
                if (!is_array($row) || array_diff(array_keys($row), ['tld', 'mode', 'whois_server', 'whois_phrase']) !== []) {
                    throw new \InvalidArgumentException('lookup');
                }
                $server = $row['whois_server'] ?? null;
                $phrase = $row['whois_phrase'] ?? null;
                if (!is_string($row['tld'] ?? null) || !is_string($row['mode'] ?? null) || ($server !== null && !is_string($server)) || ($phrase !== null && !is_string($phrase))) {
                    throw new \InvalidArgumentException('lookup');
                }
                $lookups[] = new LookupSettings($row['tld'], $row['mode'], $server, $phrase);
            }
        } catch (\InvalidArgumentException) {
            throw new ExtensionChangeRefused(ExtensionChangeRefused::INVALID);
        }

        return [$pricing, $extensions, $search, $lookups];
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Expected a whole number.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Expected a list.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Expected text.');
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
