<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

use PaxofiCloud\Application\Contracts\AuditLogSearch;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin console's audit log page (identity threat model §6.24). Needs
 * `audit.view`. The full IP address and raw user agent are shown only to
 * staff who also hold `staff.manage` (T-56); others get the IP network and
 * the parsed device. Every look is itself audited, once per search per
 * minute (T-57).
 */
final readonly class AuditLogViewer
{
    public const int VIEW_RECORD_WINDOW_SECONDS = 60;

    public function __construct(
        private StaffAuthorizer $authorizer,
        private AuditLogSearch $search,
        private RateLimiter $limiter,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input query-string values
     * @return array{
     *     head: array{seq: int, hash: string},
     *     full_context: bool,
     *     groups: list<string>,
     *     next_before: ?int,
     *     events: list<array<string, mixed>>
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     * @throws \InvalidArgumentException naming a filter that is not accepted
     */
    public function page(string $staffId, array $input, ClientContext $client): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::AuditView, $client);
        $query = AuditLogQuery::from($input);
        $fullContext = $principal->can(Permission::StaffManage);

        // One more than a page tells us whether an older page exists.
        $records = $this->search->search($query, AuditLogQuery::PAGE_SIZE + 1);
        $more = count($records) > AuditLogQuery::PAGE_SIZE;
        $records = array_slice($records, 0, AuditLogQuery::PAGE_SIZE);

        $filters = $query->filters();
        $key = 'audit-viewed:' . $staffId . ':' . hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        if ($this->limiter->hit($key, 1, self::VIEW_RECORD_WINDOW_SECONDS) === 0) {
            $this->audit->record(AuditEventType::AUDIT_VIEWED, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId, 'rows' => count($records)] + array_combine(
                array_map(static fn (string $name): string => 'filter_' . $name, array_keys($filters)),
                array_values($filters),
            ));
        }

        $last = $records === [] ? null : $records[array_key_last($records)];

        return [
            'head' => $this->search->head(),
            'full_context' => $fullContext,
            'groups' => AuditLogQuery::GROUPS,
            'next_before' => $more && $last !== null ? $last->seq : null,
            'events' => array_map(static fn (AuditRecord $r): array => self::present($r, $fullContext), $records),
        ];
    }

    /** @return array<string, mixed> */
    private static function present(AuditRecord $record, bool $fullContext): array
    {
        return [
            'seq' => $record->seq,
            'occurred_at' => $record->occurredAt->format(\DateTimeInterface::ATOM),
            'type' => $record->type,
            'outcome' => $record->outcome,
            'identity_id' => $record->identityId,
            'account_id' => $record->accountId,
            'details' => (object) $record->details,
            // T-56: the full address only for staff managers; everyone else sees the shared network.
            'ip' => $fullContext ? $record->ip : null,
            'ip_network' => $record->ipNetwork,
            'country' => $record->country,
            'region' => $record->region,
            'city' => $record->city,
            'user_agent' => $fullContext ? $record->userAgent : null,
            'device' => $record->device === null ? null : [
                'browser' => $record->device->browser,
                'browser_version' => $record->device->browserVersion,
                'os' => $record->device->os,
                'os_version' => $record->device->osVersion,
                'device_type' => $record->device->deviceType,
            ],
        ];
    }
}
