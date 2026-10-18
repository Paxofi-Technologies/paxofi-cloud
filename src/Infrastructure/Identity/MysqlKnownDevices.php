<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Identity;

use Paxofi\Core\Contracts\Connection;
use PaxofiCloud\Application\Contracts\KnownDevices;

/** MySQL storage for known devices (migration 0006). */
final readonly class MysqlKnownDevices implements KnownDevices
{
    public function __construct(private Connection $connection)
    {
    }

    public function remember(string $identityId, string $deviceHash, string $label, \DateTimeImmutable $now): bool
    {
        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        // MySQL reports 1 affected row for an insert and 2 (or 0) for an update of an existing row.
        return $this->connection->execute(
            'INSERT INTO identity_devices (identity_id, device_hash, label, first_seen_at, last_seen_at)
             VALUES (:identity_id, :device_hash, :label, :now, :now2)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), label = VALUES(label)',
            ['identity_id' => $identityId, 'device_hash' => $deviceHash, 'label' => mb_substr($label, 0, 100), 'now' => $time, 'now2' => $time],
        ) === 1;
    }
}
