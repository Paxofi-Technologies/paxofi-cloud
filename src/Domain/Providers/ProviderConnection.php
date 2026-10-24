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
    case Namecheap = 'namecheap';
    case Dynadot = 'dynadot';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';

    public const int SECRET_MAX_BYTES = 2048;
    /** How the admin console groups connections under "API providers", in display order. */
    public const array CATEGORIES = ['domains' => 'Domain registrars', 'servers' => 'Server providers'];

    public function label(): string
    {
        return match ($this) {
            self::DomainNameApi => 'DomainNameAPI (domain registrar)',
            self::Namecheap => 'Namecheap (domain registrar)',
            self::Dynadot => 'Dynadot (domain registrar)',
            self::Hetzner => 'Hetzner Cloud (servers)',
            self::Vultr => 'Vultr (servers)',
        };
    }

    /** @return key-of<self::CATEGORIES> */
    public function category(): string
    {
        return match ($this) {
            self::DomainNameApi, self::Namecheap, self::Dynadot => 'domains',
            self::Hetzner, self::Vultr => 'servers',
        };
    }

    /** @return list<ConnectionField> */
    public function fields(): array
    {
        return match ($this) {
            self::DomainNameApi => [
                new ConnectionField('reseller_id', 'Reseller ID', false, 64, null, 'DomainNameAPI reseller panel: Reseller Settings → Integration Information.'),
                new ConnectionField('api_key', 'API key', true, 256, null, 'Same page as the Reseller ID. Use the Live API key.'),
            ],
            // NC-3: Namecheap takes four values; the client IP is the server address whitelisted in the Namecheap account.
            self::Namecheap => [
                new ConnectionField('api_user', 'API user', false, 64, '/^[A-Za-z0-9_.-]{1,64}$/', 'Your Namecheap account username (the name you sign in with, shown top right in Namecheap). Not your email address.'),
                new ConnectionField('username', 'User name', false, 64, '/^[A-Za-z0-9_.-]{1,64}$/', 'For your own account, the same username as API user.'),
                new ConnectionField('api_key', 'API key', true, 128, null, 'Namecheap: Profile → Tools → Namecheap API Access → Manage. API access must be switched on.'),
                new ConnectionField('client_ip', 'Server IPv4 address (whitelisted in Namecheap)', false, 15, '/^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}$/', 'The server\'s public IPv4 address (Vultr: Products → the server). Add the same address under Whitelisted IPs on the Namecheap API Access page.'),
            ],
            // DY-2: the key authenticates, the secret only signs requests (DY-3).
            self::Dynadot => [
                new ConnectionField('api_key', 'API key', true, 256, '/^[\x21-\x7E]{8,256}$/', 'Dynadot: Tools → API. Use the production API key (not the sandbox key).'),
                new ConnectionField('api_secret', 'API secret', true, 256, '/^[\x21-\x7E]{8,256}$/', 'Same page: the API secret shown with the key. PaxofiCloud uses it only to sign requests.'),
            ],
            self::Hetzner => [new ConnectionField('api_token', 'API token (read & write)', true, 256, null, 'Hetzner Cloud Console: your project → Security → API tokens → Generate (Read & Write).')],
            self::Vultr => [new ConnectionField('api_key', 'API key', true, 256, null, 'Vultr: Account → API. Use a sub-user key with only the access PaxofiCloud needs.')],
        };
    }

    /** All of them register or create on our behalf, so their keys must be able to write. */
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
                || (!$field->secret && trim($value) !== $value)
                || ($field->pattern !== null && preg_match($field->pattern, $value) !== 1)) {
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
