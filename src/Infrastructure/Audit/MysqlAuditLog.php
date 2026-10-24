<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Audit;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditLogQuery;
use PaxofiCloud\Application\Audit\AuditRecord;
use PaxofiCloud\Application\Audit\DeviceSummary;
use PaxofiCloud\Application\Contracts\AuditLog;
use PaxofiCloud\Application\Contracts\AuditLogSearch;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL audit log (migration 0005; threat model D-14, T-39, T-51). Each
 * append locks the chain head, so sequence numbers have no gaps and every
 * row links to the one before it.
 */
final readonly class MysqlAuditLog implements AuditLog, AuditLogSearch
{
    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function append(AuditEvent $event): void
    {
        $occurredAt = $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format(AuditChain::TIME_FORMAT);
        $context = self::contextRow($event, $occurredAt);

        $this->transactions->transaction(function () use ($event, $occurredAt, $context): void {
            $head = $this->repository->fetchAll('SELECT seq, hash FROM audit_chain_head WHERE id = 1 FOR UPDATE');
            if ($head === []) {
                throw new \RuntimeException('The audit chain head is missing; run the migrations.');
            }

            $row = [
                'seq' => Row::int($head[0], 'seq') + 1,
                'id' => bin2hex(random_bytes(16)),
                'occurred_at' => $occurredAt,
                'event_type' => $event->type,
                'outcome' => $event->outcome,
                'identity_id' => $event->identityId,
                'account_id' => $event->accountId,
                'request_id' => $event->client->requestId,
                'details' => AuditChain::canonical($event->details),
                'context_commitment' => $context === null ? null : AuditChain::contextCommitment($context),
                'prev_hash' => Row::string($head[0], 'hash'),
            ];
            $row['hash'] = AuditChain::eventHash($row);

            $this->connection->execute(
                'INSERT INTO audit_events (seq, id, occurred_at, event_type, outcome, identity_id, account_id, request_id, details, context_commitment, prev_hash, hash)
                 VALUES (:seq, :id, :occurred_at, :event_type, :outcome, :identity_id, :account_id, :request_id, :details, :context_commitment, :prev_hash, :hash)',
                $row,
            );
            if ($context !== null) {
                $this->connection->execute(
                    'INSERT INTO audit_event_context (event_seq, salt, ip, ip_network, country, region, city, user_agent, browser, browser_version, os, os_version, device_type, created_at)
                     VALUES (:event_seq, :salt, :ip, :ip_network, :country, :region, :city, :user_agent, :browser, :browser_version, :os, :os_version, :device_type, :created_at)',
                    ['event_seq' => $row['seq']] + $context,
                );
            }
            $this->connection->execute('UPDATE audit_chain_head SET seq = :seq, hash = :hash WHERE id = 1', ['seq' => $row['seq'], 'hash' => $row['hash']]);
        });
    }

    public function recentForIdentity(string $identityId, int $limit): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT e.id, e.occurred_at, e.event_type, e.outcome, e.details,
                    c.ip, c.country, c.region, c.city, c.browser, c.browser_version, c.os, c.os_version, c.device_type
             FROM audit_events e LEFT JOIN audit_event_context c ON c.event_seq = e.seq
             WHERE e.identity_id = :id ORDER BY e.seq DESC LIMIT ' . max(1, min(100, $limit)),
            ['id' => $identityId],
        );

        $entries = [];
        foreach ($rows as $row) {
            $details = json_decode(Row::string($row, 'details'), true, 4, JSON_THROW_ON_ERROR);
            $hasContext = ($row['browser'] ?? null) !== null;
            $entries[] = new AuditEntry(
                Row::string($row, 'id'),
                new \DateTimeImmutable(Row::string($row, 'occurred_at'), new \DateTimeZone('UTC')),
                Row::string($row, 'event_type'),
                Row::string($row, 'outcome'),
                is_array($details) ? self::details($details) : [],
                $hasContext ? Row::string($row, 'ip') : null,
                self::nullable($row, 'country'),
                self::nullable($row, 'region'),
                self::nullable($row, 'city'),
                $hasContext ? new DeviceSummary(
                    Row::string($row, 'browser'),
                    Row::string($row, 'browser_version'),
                    Row::string($row, 'os'),
                    Row::string($row, 'os_version'),
                    Row::string($row, 'device_type'),
                ) : null,
            );
        }

        return $entries;
    }

    public function search(AuditLogQuery $query, int $limit): array
    {
        // Every condition is fixed SQL; the values were checked by AuditLogQuery and are bound here (§6.24).
        $where = ['1 = 1'];
        $params = [];
        if ($query->beforeSeq !== null) {
            $where[] = 'e.seq < :before';
            $params['before'] = $query->beforeSeq;
        }
        if ($query->outcome !== null) {
            $where[] = 'e.outcome = :outcome';
            $params['outcome'] = $query->outcome;
        }
        if ($query->group !== null) {
            $where[] = 'e.event_type LIKE :group_prefix';
            $params['group_prefix'] = $query->group . '.%';
        }
        if ($query->subject !== null) {
            // Customers are in identity_id/account_id; staff events name the member as details.subject.
            $where[] = '(e.identity_id = :subject_identity OR e.account_id = :subject_account OR e.details LIKE :subject_details)';
            $params['subject_identity'] = $query->subject;
            $params['subject_account'] = $query->subject;
            $params['subject_details'] = '%"subject":"' . $query->subject . '"%';
        }

        $rows = $this->repository->fetchAll(
            'SELECT e.seq, e.occurred_at, e.event_type, e.outcome, e.identity_id, e.account_id, e.details,
                    c.ip, c.ip_network, c.country, c.region, c.city, c.user_agent, c.browser, c.browser_version, c.os, c.os_version, c.device_type
             FROM audit_events e LEFT JOIN audit_event_context c ON c.event_seq = e.seq
             WHERE ' . implode(' AND ', $where) . ' ORDER BY e.seq DESC LIMIT ' . max(1, min(100, $limit)),
            $params,
        );

        $records = [];
        foreach ($rows as $row) {
            $details = json_decode(Row::string($row, 'details'), true, 4, JSON_THROW_ON_ERROR);
            $hasContext = ($row['browser'] ?? null) !== null;
            $records[] = new AuditRecord(
                Row::int($row, 'seq'),
                new \DateTimeImmutable(Row::string($row, 'occurred_at'), new \DateTimeZone('UTC')),
                Row::string($row, 'event_type'),
                Row::string($row, 'outcome'),
                self::nullable($row, 'identity_id'),
                self::nullable($row, 'account_id'),
                is_array($details) ? self::details($details) : [],
                $hasContext ? Row::string($row, 'ip') : null,
                $hasContext ? Row::string($row, 'ip_network') : null,
                self::nullable($row, 'country'),
                self::nullable($row, 'region'),
                self::nullable($row, 'city'),
                $hasContext ? Row::string($row, 'user_agent') : null,
                $hasContext ? new DeviceSummary(
                    Row::string($row, 'browser'),
                    Row::string($row, 'browser_version'),
                    Row::string($row, 'os'),
                    Row::string($row, 'os_version'),
                    Row::string($row, 'device_type'),
                ) : null,
            );
        }

        return $records;
    }

    public function head(): array
    {
        $rows = $this->repository->fetchAll('SELECT seq, hash FROM audit_chain_head WHERE id = 1');
        if ($rows === []) {
            throw new \RuntimeException('The audit chain head is missing; run the migrations.');
        }

        return ['seq' => Row::int($rows[0], 'seq'), 'hash' => Row::string($rows[0], 'hash')];
    }

    /** @return array<string, string|null>|null */
    private static function contextRow(AuditEvent $event, string $occurredAt): ?array
    {
        $client = $event->client;
        if ($client->ip === '' && $client->userAgent === '') {
            return null;
        }
        $device = $client->device();

        return [
            'salt' => bin2hex(random_bytes(16)),
            'ip' => $client->ip,
            'ip_network' => $client->network(),
            'country' => $client->country,
            'region' => $client->region,
            'city' => $client->city,
            'user_agent' => $client->userAgent,
            'browser' => $device->browser,
            'browser_version' => $device->browserVersion,
            'os' => $device->os,
            'os_version' => $device->osVersion,
            'device_type' => $device->deviceType,
            'created_at' => $occurredAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $details
     * @return array<string, string|int|bool>
     */
    private static function details(array $details): array
    {
        $clean = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_bool($value))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @param array<string, mixed> $row */
    private static function nullable(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
