<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Audit;

/**
 * The hashing rules of the audit chain (threat model D-14, §6.14). Writer and
 * verifier both use these, so one change here changes both.
 *
 * - Canonical JSON: keys sorted, no escaped slashes or Unicode, empty map `{}`.
 * - Row hash: SHA-256(prev_hash ‖ canonical JSON of the stored columns).
 * - Context commitment: SHA-256(salt ‖ canonical JSON of the context row);
 *   the salt lives only in the purgeable context row (T-51).
 */
final class AuditChain
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    public const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    /** The columns covered by the row hash, in the shape they are stored. */
    public const array EVENT_FIELDS = ['seq', 'id', 'occurred_at', 'event_type', 'outcome', 'identity_id', 'account_id', 'request_id', 'details', 'context_commitment', 'prev_hash'];

    public const array CONTEXT_FIELDS = ['ip', 'ip_network', 'country', 'region', 'city', 'user_agent', 'browser', 'browser_version', 'os', 'os_version', 'device_type'];

    private function __construct()
    {
    }

    /** @param array<string, mixed> $value */
    public static function canonical(array $value): string
    {
        if ($value === []) {
            return '{}';
        }
        ksort($value, SORT_STRING);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string, mixed> $row a stored row; only EVENT_FIELDS are hashed */
    public static function eventHash(array $row): string
    {
        $fields = [];
        foreach (self::EVENT_FIELDS as $name) {
            $fields[$name] = $row[$name] ?? null;
        }
        // Drivers may return BIGINT as a string; the hash input is always the integer.
        $fields['seq'] = is_numeric($fields['seq']) ? (int) $fields['seq'] : null;
        $prev = $fields['prev_hash'];

        return hash('sha256', (is_string($prev) ? $prev : '') . self::canonical($fields));
    }

    /** @param array<string, mixed> $row a stored context row with its salt */
    public static function contextCommitment(array $row): string
    {
        $fields = [];
        foreach (self::CONTEXT_FIELDS as $name) {
            $fields[$name] = $row[$name] ?? null;
        }
        $salt = $row['salt'] ?? '';

        return hash('sha256', (is_string($salt) ? $salt : '') . self::canonical($fields));
    }
}
