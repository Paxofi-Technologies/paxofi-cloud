<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;

/** Domain pricing and per-extension settings (migration 0020; DX-5, DX-9, DX-10). */
interface ExtensionSettingsStore
{
    public function pricing(): ?DomainPricingSettings;

    public function savePricing(DomainPricingSettings $settings, string $staffId, \DateTimeImmutable $at): void;

    /** @return array<string, ExtensionSettings> extension => settings, only extensions staff have touched or that are seeded */
    public function all(): array;

    public function save(ExtensionSettings $settings, string $staffId, \DateTimeImmutable $at): void;
}
