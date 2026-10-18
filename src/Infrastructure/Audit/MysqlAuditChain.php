<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Audit;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * Operator checks on the audit log (threat model T-39, T-51): verify the
 * whole chain, and purge personal request context past its retention.
 */
final readonly class MysqlAuditChain
{
    public const int CONTEXT_RETENTION_DAYS = 180;
    private const int BATCH = 1000;

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    /** Sequence, links, row hashes, context commitments and the head; stops at the first break. */
    public function verify(): AuditVerification
    {
        $expectedSeq = 1;
        $prev = AuditChain::GENESIS;
        $purged = 0;
        do {
            $rows = $this->repository->fetchAll(
                'SELECT e.seq, e.id, e.occurred_at, e.event_type, e.outcome, e.identity_id, e.account_id, e.request_id, e.details,
                        e.context_commitment, e.prev_hash, e.hash, c.event_seq AS context_seq, c.salt, c.ip, c.ip_network, c.country,
                        c.region, c.city, c.user_agent, c.browser, c.browser_version, c.os, c.os_version, c.device_type
                 FROM audit_events e LEFT JOIN audit_event_context c ON c.event_seq = e.seq
                 WHERE e.seq >= :from ORDER BY e.seq LIMIT ' . self::BATCH,
                ['from' => $expectedSeq],
            );
            foreach ($rows as $row) {
                $seq = Row::int($row, 'seq');
                if ($seq !== $expectedSeq) {
                    return new AuditVerification($expectedSeq - 1, $prev, $expectedSeq, 'missing sequence number', $purged);
                }
                if (Row::string($row, 'prev_hash') !== $prev) {
                    return new AuditVerification($seq - 1, $prev, $seq, 'link to previous event broken', $purged);
                }
                $hash = Row::string($row, 'hash');
                if (!hash_equals(AuditChain::eventHash($row), $hash)) {
                    return new AuditVerification($seq - 1, $prev, $seq, 'event content changed', $purged);
                }
                $commitment = $row['context_commitment'] ?? null;
                $hasContext = ($row['context_seq'] ?? null) !== null;
                if ($hasContext && (!is_string($commitment) || !hash_equals($commitment, AuditChain::contextCommitment($row)))) {
                    return new AuditVerification($seq - 1, $prev, $seq, 'request context changed', $purged);
                }
                if (!$hasContext && $commitment !== null) {
                    $purged++;
                }
                $prev = $hash;
                $expectedSeq++;
            }
        } while (count($rows) === self::BATCH);

        $head = $this->repository->fetchAll('SELECT seq, hash FROM audit_chain_head WHERE id = 1');
        if ($head === [] || Row::int($head[0], 'seq') !== $expectedSeq - 1 || Row::string($head[0], 'hash') !== $prev) {
            return new AuditVerification($expectedSeq - 1, $prev, $expectedSeq, 'chain head does not match the last event', $purged);
        }

        return new AuditVerification($expectedSeq - 1, $prev, contextsPurged: $purged);
    }

    /** Deletes request context recorded before the cutoff; events and their commitments stay. */
    public function purgeContext(\DateTimeImmutable $before): int
    {
        $cutoff = $before->setTimezone(new \DateTimeZone('UTC'))->format(AuditChain::TIME_FORMAT);
        $total = 0;
        do {
            $deleted = $this->connection->execute(
                'DELETE FROM audit_event_context WHERE created_at < :cutoff ORDER BY event_seq LIMIT ' . self::BATCH,
                ['cutoff' => $cutoff],
            );
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }
}
