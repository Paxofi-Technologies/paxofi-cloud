<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;

final class InMemoryDomainSearchSettingsStore implements DomainSearchSettingsStore
{
    public ?DomainSearchSettings $saved = null;
    /** @var array<string, LookupSettings> */
    public array $lookupRows = [];
    /** @var list<string> */
    public array $savedBy = [];

    public function settings(): DomainSearchSettings
    {
        return $this->saved ?? DomainSearchSettings::defaults();
    }

    public function saveSettings(DomainSearchSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->saved = $settings;
        $this->savedBy[] = $staffId;
    }

    public function lookups(): array
    {
        return $this->lookupRows;
    }

    public function saveLookup(LookupSettings $lookup, string $staffId, \DateTimeImmutable $at): void
    {
        $this->lookupRows[$lookup->tld] = $lookup;
        $this->savedBy[] = $staffId;
    }
}
