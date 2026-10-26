<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Audit\Countries;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DuplicateTaxRate;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin Tax rates page (commerce threat model CD-28). `catalogue.view`
 * reads; a change needs `catalogue.manage` plus a fresh authenticator code,
 * at most 5 tries per 15 minutes (the DX-11 rule). A change adds a row that
 * starts now or on a future date; rows are never edited or deleted, so
 * orders and invoices already issued keep their tax snapshot.
 */
final readonly class TaxRatesAdmin
{
    public const int HISTORY_SHOWN = 100;
    public const int MAX_DAYS_AHEAD = 366;
    private const int STEP_UP_ATTEMPTS = 5;
    private const int STEP_UP_WINDOW = 900;

    /** @param \Closure(string, string): bool $verifyCode staff ID, authenticator code */
    public function __construct(
        private StaffAuthorizer $authorizer,
        private TaxRateStore $rates,
        private RateLimiter $limiter,
        private \Closure $verifyCode,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{
     *     can_manage: bool,
     *     countries: list<array{country: string, country_name: string,
     *         current: ?array{tax_name: string, rate_bp: int, starts: string},
     *         scheduled: list<array{tax_name: string, rate_bp: int, starts: string}>}>,
     *     history: list<array{country: string, tax_name: string, rate_bp: int, starts: string, added_by: string}>,
     *     country_options: list<array{code: string, name: string}>
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function overview(string $staffId, ClientContext $client): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::CatalogueView, $client);
        $now = $this->clock->now();
        $byCountry = [];
        $history = [];
        foreach ($this->rates->history() as $row) {
            $entry = ['tax_name' => $row['tax_name'], 'rate_bp' => $row['rate_bp'], 'starts' => $row['effective_from']->format(\DATE_ATOM)];
            $country = $row['country'];
            $byCountry[$country] ??= ['country' => $country, 'country_name' => self::countryName($country), 'current' => null, 'scheduled' => []];
            if ($row['effective_from'] > $now) {
                // History is newest start first; show scheduled changes soonest first.
                array_unshift($byCountry[$country]['scheduled'], $entry);
            } elseif ($byCountry[$country]['current'] === null) {
                $byCountry[$country]['current'] = $entry;
            }
            if (count($history) < self::HISTORY_SHOWN) {
                $history[] = ['country' => $country] + $entry + ['added_by' => $row['created_by']];
            }
        }
        uksort($byCountry, static fn (string $a, string $b): int => [$a === TaxRate::FALLBACK_COUNTRY, $a] <=> [$b === TaxRate::FALLBACK_COUNTRY, $b]);

        return [
            'can_manage' => $principal->can(Permission::CatalogueManage),
            'countries' => array_values($byCountry),
            'history' => $history,
            'country_options' => self::countryOptions(),
        ];
    }

    /**
     * @param array<array-key, mixed> $input country, tax_name, rate_bp, starts ("now" or YYYY-MM-DD), code
     * @throws TaxRateChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function change(string $staffId, ClientContext $client, array $input): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $retry = $this->limiter->hit('staff:tax_rates:' . $staffId, self::STEP_UP_ATTEMPTS, self::STEP_UP_WINDOW);
        if ($retry > 0) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::THROTTLED, $retry);
        }
        $code = $input['code'] ?? null;
        if (!is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || !($this->verifyCode)($staffId, $code)) {
            $this->audit->record(AuditEventType::TAX_RATE_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: ['actor' => $staffId, 'reason' => TaxRateChangeRefused::STEP_UP]);

            throw new TaxRateChangeRefused(TaxRateChangeRefused::STEP_UP);
        }
        if (array_diff(array_keys($input), ['country', 'tax_name', 'rate_bp', 'starts', 'code']) !== []) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::INVALID);
        }
        $rate = self::rate($input);
        $now = $this->clock->now();
        $starts = self::starts($input['starts'] ?? null, $now);
        try {
            $this->rates->add($rate, $starts, $staffId);
        } catch (DuplicateTaxRate) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::DUPLICATE_START);
        }
        $this->audit->record(AuditEventType::TAX_RATE_CHANGED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'country' => strtolower($rate->country),
            'rate_bp' => $rate->rate->value,
            'starts' => $starts->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His'),
            'immediate' => $starts == $now,
        ]);
    }

    /** @param array<array-key, mixed> $input */
    private static function rate(array $input): TaxRate
    {
        $country = $input['country'] ?? null;
        $name = $input['tax_name'] ?? null;
        $basisPoints = $input['rate_bp'] ?? null;
        if (!is_string($country) || ($country !== TaxRate::FALLBACK_COUNTRY && !Countries::known($country))
            || !is_string($name) || preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} .,&()'\\/-]{0,39}$/u", $name) !== 1
            || !is_int($basisPoints) || $basisPoints < 0 || $basisPoints > BasisPoints::MAX) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::INVALID);
        }

        return new TaxRate($country, $name, new BasisPoints($basisPoints));
    }

    /** "now", or a date (UTC midnight) after now and at most MAX_DAYS_AHEAD days ahead; never in the past (C-48). */
    private static function starts(mixed $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($value === 'now') {
            return $now;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::INVALID);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::INVALID);
        }
        if ($date <= $now) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::START_IN_PAST);
        }
        if ($date > $now->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            throw new TaxRateChangeRefused(TaxRateChangeRefused::INVALID);
        }

        return $date;
    }

    /** @return list<array{code: string, name: string}> */
    private static function countryOptions(): array
    {
        $options = [['code' => TaxRate::FALLBACK_COUNTRY, 'name' => self::countryName(TaxRate::FALLBACK_COUNTRY)]];
        foreach (Countries::all() as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }

    private static function countryName(string $code): string
    {
        return $code === TaxRate::FALLBACK_COUNTRY ? 'Everyone else' : Countries::name($code);
    }
}
