<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Payments;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\GatewayEventStore;
use PaxofiCloud\Application\Payments\PaymentVerifier;
use PaxofiCloud\Application\Payments\WebhookHint;

/**
 * Gateway events in MySQL (THREAT-MODEL-PAYMENTS.md PY-7). The event key is
 * the SHA-256 of the event name, our reference and the payload hash: a
 * gateway retry sends the same body, so it is the same key; neither gateway
 * gives an event ID of its own. The insert and the "check now" update commit
 * together, before the endpoint answers 200. A closed attempt the event names
 * is reopened for one last check (PY-12): a late payment is real money.
 */
final readonly class MysqlGatewayEventStore implements GatewayEventStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private TransactionManager $transactions)
    {
    }

    public function record(WebhookHint $hint, string $payloadSha256, \DateTimeImmutable $now): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $payloadSha256) !== 1) {
            throw new \InvalidArgumentException('The payload hash is a SHA-256 in hex.');
        }
        $at = $now->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
        try {
            return (bool) $this->transactions->transaction(function () use ($hint, $payloadSha256, $at): bool {
                $this->connection->execute(
                    'INSERT INTO gateway_events (id, gateway, event_key, event, reference, payload_sha256, received_at)
                     VALUES (:id, :gateway, :key, :event, :reference, :payload, :at)',
                    [
                        'id' => bin2hex(random_bytes(16)),
                        'gateway' => $hint->gateway->value,
                        'key' => hash('sha256', $hint->event . '|' . $hint->reference->value . '|' . $payloadSha256),
                        'event' => $hint->event,
                        'reference' => $hint->reference->value,
                        'payload' => $payloadSha256,
                        'at' => $at,
                    ],
                );
                // PY-8: the webhook is only a reason to ask the gateway now; the verifier decides.
                $this->connection->execute(
                    "UPDATE payment_attempts SET next_check_at = :now
                     WHERE reference = :reference AND gateway = :gateway AND state IN ('pending', 'unknown')
                       AND (next_check_at IS NULL OR next_check_at > :now2)",
                    ['now' => $at, 'now2' => $at, 'reference' => $hint->reference->value, 'gateway' => $hint->gateway->value],
                );
                // PY-12: an attempt already closed as failed or abandoned may still have been paid
                // (late payment). It gets exactly one more check: unknown, due now, on its last
                // scheduled check, so a non-success answer closes it again.
                $this->connection->execute(
                    "UPDATE payment_attempts SET state = 'unknown', checks = :last, next_check_at = :now, state_changed_at = :now2
                     WHERE reference = :reference AND gateway = :gateway AND state IN ('failed', 'abandoned') AND checkout_url IS NOT NULL",
                    ['last' => count(PaymentVerifier::SCHEDULE_SECONDS) - 1, 'now' => $at, 'now2' => $at, 'reference' => $hint->reference->value, 'gateway' => $hint->gateway->value],
                );

                return true;
            });
        } catch (\RuntimeException $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException && ($cause->errorInfo[1] ?? null) === 1062 && str_contains($cause->getMessage(), 'uq_gateway_events_key')) {
                    return false;
                }
            }
            throw $e;
        }
    }
}
