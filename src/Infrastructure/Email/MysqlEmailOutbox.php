<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL outbox (threat model E-2/E-3/E-5). enqueue() runs on the caller's
 * connection, so it commits or rolls back with the action that needs the
 * email. Payloads are sealed before they reach SQL.
 */
final readonly class MysqlEmailOutbox implements EmailOutbox, OutboxStore
{
    private const string TIME = 'Y-m-d H:i:s.u';
    /** Upper bound on one claim; callers may take fewer (rows they skip are released at commit). */
    public const int MAX_CLAIM = 50;

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private OutboxSealer $sealer,
        private Clock $clock,
    ) {
    }

    public function enqueue(EmailAddress $to, string $template, array $parameters): string
    {
        if (!in_array($template, EmailTemplates::names(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }
        $id = bin2hex(random_bytes(16));
        $payload = $this->sealer->seal(json_encode(
            ['id' => $id, 'template' => $template, 'to' => $to->value, 'parameters' => $parameters],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $this->connection->execute(
            'INSERT INTO email_outbox (id, template, recipient_hash, payload, status, next_attempt_at)
             VALUES (:id, :template, :recipient_hash, :payload, \'pending\', :due)',
            ['id' => $id, 'template' => $template, 'recipient_hash' => $to->hash(), 'payload' => $payload, 'due' => self::time($this->clock->now())],
        );

        return $id;
    }

    public function claim(int $limit, \DateTimeImmutable $now, int $lockSeconds): array
    {
        if ($limit < 1) {
            return [];
        }
        $this->connection->beginTransaction();
        try {
            $rows = $this->repository->fetchAll(
                'SELECT id, template, recipient_hash, payload, attempts FROM email_outbox
                 WHERE (status = \'pending\' AND next_attempt_at <= :now)
                    OR (status = \'sending\' AND locked_until < :now2)
                 ORDER BY next_attempt_at, id LIMIT ' . self::MAX_CLAIM . ' FOR UPDATE SKIP LOCKED',
                ['now' => self::time($now), 'now2' => self::time($now)],
            );
            $items = [];
            foreach (array_slice($rows, 0, $limit) as $row) {
                $items[] = new OutboxItem(Row::string($row, 'id'), Row::string($row, 'template'), Row::string($row, 'recipient_hash'), Row::string($row, 'payload'), Row::int($row, 'attempts'));
                $this->connection->execute(
                    'UPDATE email_outbox SET status = \'sending\', locked_until = :until WHERE id = :id',
                    ['until' => self::time($now->modify(sprintf('+%d seconds', $lockSeconds))), 'id' => Row::string($row, 'id')],
                );
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $items;
    }

    public function markSent(string $id, string $provider, string $messageId, \DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'UPDATE email_outbox SET status = \'sent\', payload = NULL, attempts = attempts + 1, locked_until = NULL,
                provider = :provider, provider_message_id = :message_id, last_error = NULL, sent_at = :now WHERE id = :id',
            ['provider' => $provider, 'message_id' => substr($messageId, 0, 128), 'now' => self::time($now), 'id' => $id],
        );
    }

    public function reschedule(string $id, int $attempts, \DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->connection->execute(
            'UPDATE email_outbox SET status = \'pending\', attempts = :attempts, next_attempt_at = :next, locked_until = NULL, last_error = :error WHERE id = :id',
            ['attempts' => $attempts, 'next' => self::time($nextAttemptAt), 'error' => mb_substr($error, 0, 255), 'id' => $id],
        );
    }

    public function markDead(string $id, int $attempts, string $reason): void
    {
        $this->connection->execute(
            'UPDATE email_outbox SET status = \'dead\', payload = NULL, attempts = :attempts, locked_until = NULL, last_error = :reason WHERE id = :id',
            ['attempts' => $attempts, 'reason' => mb_substr($reason, 0, 255), 'id' => $id],
        );
    }

    public function sentSince(\DateTimeImmutable $since): int
    {
        $rows = $this->repository->fetchAll('SELECT COUNT(*) AS n FROM email_outbox WHERE sent_at >= :since', ['since' => self::time($since)]);

        return Row::int($rows[0] ?? ['n' => 0], 'n');
    }

    private static function time(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
