<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainCostReport;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;

/**
 * Selling prices per extension for customers (THREAT-MODEL-DOMAIN-PRICING.md
 * DX-5, DX-13): the same registrar choice and price rules as the admin
 * Domain extensions page, loaded once per request. Only selling prices,
 * featured order and labels come out; costs and registrars never do.
 */
final class ExtensionQuotes
{
    /** @var array<string, list<RegistrarOffer>>|null */
    private ?array $offers = null;
    /** @var array<string, ExtensionSettings>|null */
    private ?array $settings = null;
    private bool $loaded = false;
    private ?DomainPricingSettings $pricing = null;
    private ?ExchangeRates $exchange = null;

    /** @param array<string, string> $registrars provider => environment */
    public function __construct(
        private readonly array $registrars,
        private readonly DomainCostReport $costs,
        private readonly ExtensionSettingsStore $store,
        private readonly FxRateStore $rates,
        private readonly Clock $clock,
    ) {
    }

    /** The register and renew price, or null when the extension is not sold online. */
    public function quote(string $tld): ?ExtensionPrices
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->pricing = $this->store->pricing();
            $this->exchange = $this->rates->latestAccepted()?->rates;
        }
        if ($this->pricing === null || $this->exchange === null) {
            return null;
        }
        $choice = RegistrarChoice::choose($this->offers()[$tld] ?? [], ($this->settings()[$tld] ?? null)?->pinnedRegistrar, $this->clock->now());
        if ($choice->chosen === null) {
            return null;
        }
        $prices = ExtensionPrices::for($choice->chosen->cost, $this->pricing, $this->exchange);

        return $prices->usd['register'] === null ? null : $prices;
    }

    /** @return list<string> every extension some registrar offers, alphabetical; quote() decides which are sold */
    public function extensions(): array
    {
        $tlds = array_map('strval', array_keys($this->offers()));
        sort($tlds);

        return $tlds;
    }

    /** @return list<string> featured extensions in their order (CR-003, DX-10) */
    public function featured(): array
    {
        $featured = array_filter($this->settings(), static fn (ExtensionSettings $s): bool => $s->featuredOrder !== null);
        uasort($featured, static fn (ExtensionSettings $a, ExtensionSettings $b): int => $a->featuredOrder <=> $b->featuredOrder);

        return array_values(array_map(static fn (ExtensionSettings $s): string => $s->tld->value, $featured));
    }

    public function label(string $tld): string
    {
        return isset($this->settings()[$tld]) ? $this->settings()[$tld]->label : 'none';
    }

    /** @return array<string, list<RegistrarOffer>> */
    private function offers(): array
    {
        if ($this->offers === null) {
            $this->offers = [];
            foreach ($this->registrars as $registrar => $environment) {
                $snapshot = $this->costs->latestSnapshot($registrar, $environment, true);
                if ($snapshot === null) {
                    continue;
                }
                foreach ($this->costs->items($snapshot->id) as $cost) {
                    $this->offers[$cost->tld->value][] = new RegistrarOffer($registrar, $cost, $snapshot->fetchedAt);
                }
            }
        }

        return $this->offers;
    }

    /** @return array<string, ExtensionSettings> */
    private function settings(): array
    {
        return $this->settings ??= $this->store->all();
    }
}
