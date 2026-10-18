<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Audit;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\DeviceSummary;
use PaxofiCloud\Application\Contracts\AuditLog;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL audit log (migration 0005; threat model D-14, T-39, T-51). Each
 * append locks the chain head, so sequence numbers have no gaps and every
 * row links to the one before it.
 */
final readonly class MysqlAuditLog implements AuditLog
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
