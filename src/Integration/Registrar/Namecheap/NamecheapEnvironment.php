<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

/**
 * Which Namecheap API to call. The URLs are fixed here, never taken from
 * configuration; the setting only chooses between them
 * (THREAT-MODEL-DOMAIN-PRICING.md NC-2).
 */
enum NamecheapEnvironment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Live => 'https://api.namecheap.com',
            self::Sandbox => 'https://api.sandbox.namecheap.com',
        };
    }

    /** NAMECHEAP_ENVIRONMENT: empty means live; production never uses the sandbox. */
    public static function fromSetting(string $setting, string $appEnv): self
    {
        $environment = $setting === '' ? self::Live : (self::tryFrom($setting) ?? throw new \InvalidArgumentException('NAMECHEAP_ENVIRONMENT must be live or sandbox.'));
        if ($environment === self::Sandbox && $appEnv === 'production') {
            throw new \InvalidArgumentException('Production must use the live Namecheap API.');
        }

        return $environment;
    }
}
