<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Payments;

use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;

/** Shared, fixed-reason reading of gateway HTTP answers (PY-19). Numbers stay exact text (ExactJson). */
final class GatewayResponses
{
    public const int MAX_BODY_BYTES = 262_144;

    /** Classifies statuses that are failures whatever the body says. */
    public static function failIfUnusable(int $status): void
    {
        if (in_array($status, [301, 302, 307, 308, 401, 403], true)) {
            throw new GatewayUnavailable('unauthorised');
        }
        if ($status === 429) {
            throw new GatewayUnavailable('rate_limited');
        }
        if ($status >= 500 || $status < 200) {
            throw new GatewayUnavailable('provider_error');
        }
    }

    /** @return array<array-key, mixed> */
    public static function document(string $body): array
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new GatewayUnavailable('malformed');
        }
        try {
            return ExactJson::decode($body, 16);
        } catch (MalformedProviderResponse) {
            throw new GatewayUnavailable('malformed');
        }
    }

    /**
     * The URL is https on exactly one of the gateway's checkout hosts, with nothing that could smuggle another destination.
     *
     * @param list<string> $hosts
     */
    public static function checkoutUrl(mixed $url, array $hosts): string
    {
        if (!is_string($url) || strlen($url) > 512 || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) === 1) {
            throw new GatewayUnavailable('unexpected_checkout_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GatewayUnavailable('unexpected_checkout_url');
        }

        return $url;
    }

    /** Gateway transaction IDs are positive integers; kept as text (Paystack: unsigned 64-bit). */
    public static function transactionId(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) === 1 ? $id : null;
    }

    public static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
