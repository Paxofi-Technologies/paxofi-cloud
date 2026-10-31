<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;

/** Staff settings for the domain search and per-extension lookups (DS-21 to DS-23). */
interface DomainSearchSettingsStore
{
    /** The saved settings, or the defaults when staff have not saved any. */
    public function settings(): DomainSearchSettings;

    public function saveSettings(DomainSearchSettings $settings, string $staffId, \DateTimeImmutable $at): void;

    /** @return array<string, LookupSettings> by extension; an extension without a row uses `auto` */
    public function lookups(): array;

    public function saveLookup(LookupSettings $lookup, string $staffId, \DateTimeImmutable $at): void;
}
