<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;

final class InMemoryExtensionSettingsStore implements ExtensionSettingsStore
{
    public ?DomainPricingSettings $pricing = null;
    /** @var array<string, ExtensionSettings> */
    public array $extensions = [];

    public function pricing(): ?DomainPricingSettings
    {
        return $this->pricing;
    }

    public function savePricing(DomainPricingSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->pricing = $settings;
    }

    public function all(): array
    {
        return $this->extensions;
    }

    public function save(ExtensionSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->extensions[$settings->tld->value] = $settings;
    }
}
