<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/**
 * Who and where a request came from (threat model §6.14, T-50): the client
 * IP from the trusted proxy hop, the location Cloudflare reported for it and
 * the user agent. Every value is cleaned here, so it is safe to store and
 * show; location and device are approximate and never decide anything.
 */
final readonly class ClientContext
{
    private function __construct(
        public string $ip,
        public string $userAgent,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public string $requestId,
    ) {
    }

    public static function from(string $ip, string $userAgent, ?string $country, ?string $region, ?string $city, string $requestId): self
    {
        return new self(
            filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            self::text($userAgent, 512) ?? '',
            $country !== null && preg_match('/^[A-Z0-9]{2}$/', $country) === 1 && $country !== 'XX' ? $country : null,
            self::text($region, 64),
            self::text($city, 64),
            preg_match('/^[A-Za-z0-9-]{1,64}$/', $requestId) === 1 ? $requestId : '',
        );
    }

    /** For callers with nothing but an IP (tests, CLI). */
    public static function ip(string $ip): self
    {
        return self::from($ip, '', null, null, null, '');
    }

    public function device(): DeviceSummary
    {
        return DeviceSummary::fromUserAgent($this->userAgent);
    }

    /** "203.0.113.0/24" or "2001:db8:1:2::/64": the network a household or office shares; '' if no IP. */
    public function network(): string
    {
        $packed = @inet_pton($this->ip);
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop(substr($packed, 0, 3) . "\0") . '/24';
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Valid UTF-8, no control characters, trimmed and capped; null when nothing is left. */
    private static function text(?string $value, int $max): ?string
    {
        if ($value === null || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
