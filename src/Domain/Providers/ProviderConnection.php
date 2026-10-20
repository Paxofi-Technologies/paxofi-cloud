<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Providers;

/**
 * The providers whose API credentials staff may enter in the admin console,
 * and exactly which fields each takes (threat model PC-4). The credential ID,
 * environment and access level are derived here, never from a request.
 */
enum ProviderConnection: string
{
    case DomainNameApi = 'domainnameapi';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';

    public const int SECRET_MAX_BYTES = 2048;

    public function label(): string
    {
        return match ($this) {
            self::DomainNameApi => 'DomainNameAPI (domain registrar)',
            self::Hetzner => 'Hetzner Cloud (servers)',
            self::Vultr => 'Vultr (servers)',
        };
    }

    /** @return list<ConnectionField> */
    public function fields(): array
    {
        return match ($this) {
            self::DomainNameApi => [
                new ConnectionField('username', 'Reseller username', false, 128),
                new ConnectionField('password', 'Reseller password', true, 256),
            ],
            self::Hetzner => [new ConnectionField('api_token', 'API token (read & write)', true, 256)],
            self::Vultr => [new ConnectionField('api_key', 'API key', true, 256)],
        };
    }

    /** All three register or create on our behalf, so their keys must be able to write. */
    public function accessLevel(): string
    {
        return 'read-write';
    }

    /** @param 'dev'|'staging'|'prod' $environment */
    public function credentialId(string $environment): string
    {
        return $this->value . '-' . $environment;
    }

    /**
     * Checks submitted values against this provider's fields and returns the
     * secret to store: the bare value for one-field providers, otherwise a
     * JSON object of all fields. Every field is required, no field may be
     * added, and control characters are refused.
     *
     * @param array<array-key, mixed> $values
     * @throws InvalidConnectionFields naming the offending field (never its value)
     */
    public function secretFrom(#[\SensitiveParameter] array $values): string
    {
        $fields = $this->fields();
        $names = array_map(static fn (ConnectionField $f): string => $f->name, $fields);
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $names, true)) {
                throw new InvalidConnectionFields('unknown_field');
            }
        }
        $clean = [];
        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > $field->maxLength
                || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
                || (!$field->secret && trim($value) !== $value)) {
                throw new InvalidConnectionFields($field->name);
            }
            $clean[$field->name] = $value;
        }

        return count($clean) === 1 ? (string) reset($clean) : json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return 'dev'|'staging'|'prod' */
    public static function environmentFor(string $appEnv): string
    {
        return match ($appEnv) {
            'production' => 'prod',
            'staging' => 'staging',
            default => 'dev',
        };
    }
}
