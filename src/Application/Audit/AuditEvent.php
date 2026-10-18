<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/**
 * One security event to append (SRS AUD-001; threat model D-14, T-51).
 * Details are fixed codes only, never free text, emails, names or secrets:
 * keys and string values are restricted to short identifier characters.
 */
final readonly class AuditEvent
{
    public const string SUCCESS = 'success';
    public const string FAILURE = 'failure';

    /** @param array<string, string|int|bool> $details */
    public function __construct(
        public string $type,
        public string $outcome,
        public \DateTimeImmutable $occurredAt,
        public ClientContext $client,
        public ?string $identityId = null,
        public ?string $accountId = null,
        public array $details = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]{2,63}$/', $type) !== 1) {
            throw new \InvalidArgumentException('Audit event type must be a dotted lower-case name.');
        }
        if ($outcome !== self::SUCCESS && $outcome !== self::FAILURE) {
            throw new \InvalidArgumentException('Audit outcome must be success or failure.');
        }
        foreach ([$identityId, $accountId] as $id) {
            if ($id !== null && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) !== 1) {
                throw new \InvalidArgumentException('Audit identity and account IDs must be 1-32 ID characters.');
            }
        }
        if (count($details) > 8) {
            throw new \InvalidArgumentException('Audit details hold at most 8 fields.');
        }
        foreach ($details as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) !== 1
                || (is_string($value) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $value) !== 1)) {
                throw new \InvalidArgumentException('Audit details must be short fixed codes.');
            }
        }
    }
}
