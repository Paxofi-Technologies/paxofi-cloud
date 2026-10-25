<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/**
 * A staff search of the audit log (identity threat model §6.24): newest
 * first, a page at a time, optionally narrowed by outcome, event-type group
 * and one subject. Every value is checked against a fixed list or pattern
 * here, before it reaches a query, and is then bound, never concatenated.
 */
final readonly class AuditLogQuery
{
    public const int PAGE_SIZE = 50;

    /** Event-type groups staff may filter by: the prefix before the first dot. */
    public const array GROUPS = ['auth', 'identity', 'mfa', 'staff', 'provider_credential', 'provider_prices', 'domain_pricing', 'registrar', 'audit', 'billing'];

    private function __construct(
        public ?int $beforeSeq,
        public ?string $outcome,
        public ?string $group,
        public ?string $subject,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input query-string values
     * @throws \InvalidArgumentException naming the field that is not accepted
     */
    public static function from(array $input): self
    {
        $value = static function (string $name) use ($input): ?string {
            $raw = $input[$name] ?? null;
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!is_string($raw)) {
                throw new \InvalidArgumentException($name);
            }

            return $raw;
        };

        $before = $value('before');
        if ($before !== null && preg_match('/^[1-9][0-9]{0,17}$/', $before) !== 1) {
            throw new \InvalidArgumentException('before');
        }
        $outcome = $value('outcome');
        if ($outcome !== null && !in_array($outcome, [AuditEvent::SUCCESS, AuditEvent::FAILURE], true)) {
            throw new \InvalidArgumentException('outcome');
        }
        $group = $value('group');
        if ($group !== null && !in_array($group, self::GROUPS, true)) {
            throw new \InvalidArgumentException('group');
        }
        $subject = $value('subject');
        if ($subject !== null) {
            $subject = strtolower($subject);
            if (preg_match('/^[0-9a-f]{32}$/', $subject) !== 1) {
                throw new \InvalidArgumentException('subject');
            }
        }

        return new self($before === null ? null : (int) $before, $outcome, $group, $subject);
    }

    /**
     * The filters as fixed codes for the `audit.viewed` event (T-57). The page
     * position is left out: paging through one search is one look.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter(['outcome' => $this->outcome, 'group' => $this->group, 'subject' => $this->subject], static fn (?string $v): bool => $v !== null);
    }
}
