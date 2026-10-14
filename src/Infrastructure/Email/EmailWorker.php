<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailTemplates;

/**
 * Sends queued email (threat model E-2..E-10):
 * claim → open the sealed payload → check it belongs to this row → recipient
 * policy → render → send (with failover) → record the outcome.
 *
 * At most MAX_ATTEMPTS attempts with exponential backoff and jitter, then the
 * row is dead-lettered; a global hourly cap protects provider quota and
 * reputation. Logs carry the outbox ID, template, recipient hash, provider
 * and outcome only, never addresses, tokens, links or bodies.
 */
final readonly class EmailWorker
{
    public const int MAX_ATTEMPTS = 5;
    private const int BASE_DELAY_SECONDS = 60;
    private const int MAX_DELAY_SECONDS = 1800;
    private const int LOCK_SECONDS = 300;

    public function __construct(
        private OutboxStore $store,
        private OutboxSealer $sealer,
        private EmailTemplates $templates,
        private TransactionalEmailSender $sender,
        private RecipientPolicy $recipients,
        private Clock $clock,
        private Logger $logger,
        private int $hourlyCap = 500,
    ) {
        if ($hourlyCap < 1) {
            throw new \InvalidArgumentException('Hourly email cap must be positive.');
        }
    }

    /** @return int rows processed (sent, rescheduled or dead-lettered) */
    public function runOnce(int $batchSize = 20): int
    {
        $now = $this->clock->now();
        $sentLastHour = $this->store->sentSince($now->modify('-1 hour'));
        if ($sentLastHour >= $this->hourlyCap) {
            $this->logger->warning('email.cap_reached', ['sent_last_hour' => $sentLastHour, 'cap' => $this->hourlyCap]);

            return 0;
        }

        $items = $this->store->claim(min($batchSize, $this->hourlyCap - $sentLastHour), $now, self::LOCK_SECONDS);
        foreach ($items as $item) {
            $this->process($item);
        }

        return count($items);
    }

    private function process(OutboxItem $item): void
    {
        $attempts = $item->attempts + 1;
        $context = ['outbox_id' => $item->id, 'template' => $item->template, 'recipient' => $item->recipientHash, 'attempt' => $attempts];

        try {
            [$to, $parameters] = $this->openPayload($item);
        } catch (\UnexpectedValueException $e) {
            $this->store->markDead($item->id, $attempts, 'payload-invalid');
            $this->logger->error('email.payload_invalid', $context + ['reason' => $e->getMessage()]);

            return;
        }

        if (!$this->recipients->allows($to)) {
            $this->store->markDead($item->id, $attempts, 'suppressed-not-allowlisted');
            $this->logger->info('email.suppressed', $context);

            return;
        }

        try {
            $message = $this->templates->render($to, $item->template, $parameters, $item->id);
        } catch (\InvalidArgumentException) {
            $this->store->markDead($item->id, $attempts, 'render-failed');
            $this->logger->error('email.render_failed', $context);

            return;
        }

        try {
            $receipt = $this->sender->send($message);
        } catch (EmailDeliveryFailed $failure) {
            $this->failed($item, $attempts, $failure, $context);

            return;
        }

        $this->store->markSent($item->id, $receipt->provider, $receipt->messageId, $this->clock->now());
        $this->logger->info('email.sent', $context + ['provider' => $receipt->provider, 'provider_message_id' => $receipt->messageId]);
    }

    /** @param array<string, mixed> $context */
    private function failed(OutboxItem $item, int $attempts, EmailDeliveryFailed $failure, array $context): void
    {
        $context += ['provider' => $failure->provider, 'kind' => $failure->kind->value, 'reason' => $failure->getMessage()];

        if ($failure->kind === DeliveryFailureKind::Rejected) {
            $this->store->markDead($item->id, $attempts, 'rejected: ' . $failure->getMessage());
            $this->logger->error('email.rejected', $context);

            return;
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->store->markDead($item->id, $attempts, 'retries-exhausted: ' . $failure->getMessage());
            $this->logger->error('email.dead_lettered', $context);

            return;
        }

        $delay = min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * 2 ** ($attempts - 1));
        $delay = random_int((int) ($delay * 0.8), (int) ($delay * 1.2));
        $this->store->reschedule($item->id, $attempts, $this->clock->now()->modify(sprintf('+%d seconds', $delay)), $failure->getMessage());
        $level = $failure->kind === DeliveryFailureKind::Configuration ? 'error' : 'warning';
        $this->logger->{$level}('email.retry_scheduled', $context + ['delay_seconds' => $delay]);
    }

    /**
     * Opens the payload and checks it was sealed for this very row, so a
     * ciphertext copied onto another row (different recipient) is refused.
     *
     * @return array{EmailAddress, array<string, string>}
     */
    private function openPayload(OutboxItem $item): array
    {
        $data = json_decode($this->sealer->open($item->payload), true);
        if (!is_array($data) || ($data['id'] ?? null) !== $item->id || ($data['template'] ?? null) !== $item->template
            || !is_string($data['to'] ?? null) || !is_array($data['parameters'] ?? null)) {
            throw new \UnexpectedValueException('Outbox payload does not belong to this row.');
        }
        try {
            $to = new EmailAddress($data['to']);
        } catch (\InvalidArgumentException) {
            throw new \UnexpectedValueException('Outbox payload recipient is invalid.');
        }
        if ($to->hash() !== $item->recipientHash) {
            throw new \UnexpectedValueException('Outbox payload recipient does not match the row.');
        }
        $parameters = [];
        foreach ($data['parameters'] as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \UnexpectedValueException('Outbox payload parameters are malformed.');
            }
            $parameters[$key] = $value;
        }

        return [$to, $parameters];
    }
}
