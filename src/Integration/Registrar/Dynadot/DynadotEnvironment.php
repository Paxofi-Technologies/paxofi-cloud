<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

/**
 * Which Dynadot RESTful API to call. The URLs are fixed here, never taken
 * from configuration; the setting only chooses between them
 * (THREAT-MODEL-DOMAIN-PRICING.md DY-4, DY-8).
 */
enum DynadotEnvironment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Live => 'https://api.dynadot.com/restful/v2',
            self::Sandbox => 'https://api-sandbox.dynadot.com/restful/v2',
        };
    }

    /** DYNADOT_ENVIRONMENT: empty means live; production never uses the sandbox. */
    public static function fromSetting(string $setting, string $appEnv): self
    {
        $environment = $setting === '' ? self::Live : (self::tryFrom($setting) ?? throw new \InvalidArgumentException('DYNADOT_ENVIRONMENT must be live or sandbox.'));
        if ($environment === self::Sandbox && $appEnv === 'production') {
            throw new \InvalidArgumentException('Production must use the live Dynadot API.');
        }

        return $environment;
    }
}
