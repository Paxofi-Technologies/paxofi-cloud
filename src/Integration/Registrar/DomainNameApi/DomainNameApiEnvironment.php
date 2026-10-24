<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

/**
 * Which DomainNameAPI gateway to call. The URLs are fixed here, never taken
 * from configuration; the setting only chooses between them (DR-2).
 */
enum DomainNameApiEnvironment: string
{
    case Live = 'live';
    case Ote = 'ote';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Live => 'https://api.domainresellerapi.com/api/v1',
            self::Ote => 'https://ote.domainresellerapi.com/api/v1',
        };
    }

    /** DOMAINNAMEAPI_ENVIRONMENT: empty means live; production never uses the test gateway (DR-T7). */
    public static function fromSetting(string $setting, string $appEnv): self
    {
        $environment = $setting === '' ? self::Live : (self::tryFrom($setting) ?? throw new \InvalidArgumentException('DOMAINNAMEAPI_ENVIRONMENT must be live or ote.'));
        if ($environment === self::Ote && $appEnv === 'production') {
            throw new \InvalidArgumentException('Production must use the live DomainNameAPI gateway.');
        }

        return $environment;
    }
}
