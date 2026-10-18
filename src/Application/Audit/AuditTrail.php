<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\AuditLog;
use PaxofiCloud\Application\Contracts\Clock;

/**
 * Records security events for the identity services (threat model §6.14).
 * Best effort by design: the action has already happened, so a failed write
 * is logged at error level for alerting and never undoes or blocks it.
 */
final readonly class AuditTrail
{
    public const int ACTIVITY_LIMIT = 20;

    public function __construct(private AuditLog $log, private Clock $clock, private Logger $logger)
    {
    }

    /** @param array<string, string|int|bool> $details fixed codes only */
    public function record(
        string $type,
        string $outcome,
        ClientContext $client,
        ?string $identityId = null,
        ?string $accountId = null,
        array $details = [],
    ): void {
        try {
            $this->log->append(new AuditEvent($type, $outcome, $this->clock->now(), $client, $identityId, $accountId, $details));
        } catch (\Throwable $e) {
            $this->logger->error('audit.write_failed', ['event_type' => $type, 'error' => $e::class]);
        }
    }

    /** @return list<AuditEntry> */
    public function recentForIdentity(string $identityId): array
    {
        return $this->log->recentForIdentity($identityId, self::ACTIVITY_LIMIT);
    }
}
