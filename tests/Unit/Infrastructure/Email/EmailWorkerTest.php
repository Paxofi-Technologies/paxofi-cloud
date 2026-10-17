<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Email;

use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Email\FailoverEmailSender;
use PaxofiCloud\Infrastructure\Email\EmailWorker;
use PaxofiCloud\Infrastructure\Email\OutboxItem;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Email\RecipientPolicy;
use PaxofiCloud\Tests\Support\InMemoryOutbox;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\ScriptedEmailSender;
use PHPUnit\Framework\TestCase;

final class EmailWorkerTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz012345ABCDEFGH';

    private MutableClock $clock;
    private OutboxSealer $worker;
    private InMemoryOutbox $outbox;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->worker = OutboxSealer::forOpening($pair['public'], $pair['secret']);
        // The web tier only ever holds the public key.
        $this->outbox = new InMemoryOutbox(OutboxSealer::forSealing($pair['public']), $this->clock);
        $this->logger = new RecordingLogger();
    }

    public function testSendsRendersAndPurgesThePayload(): void
    {
        $id = $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['name' => 'Ada', 'token' => self::TOKEN]);
        $sender = new ScriptedEmailSender();

        self::assertSame(1, $this->worker($sender)->runOnce());

        self::assertSame('sent', $this->outbox->rows[$id]['status']);
        self::assertNull($this->outbox->rows[$id]['payload'], 'E-3: payload purged after send');
        self::assertSame($id, $sender->sent[0]->idempotencyKey);
        self::assertStringContainsString(self::TOKEN, $sender->sent[0]->textBody);
    }

    /** M-08/E-10: logs carry references only, never the address, token or link. */
    public function testLogsNeverContainAddressesOrTokens(): void
    {
        $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $this->worker(new ScriptedEmailSender('postmark', [DeliveryFailureKind::Transient]))->runOnce();
        $this->clock->advance('+1 day');
        $this->worker(new ScriptedEmailSender())->runOnce();

        $logs = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ada@example.ng', $logs);
        self::assertStringNotContainsString(self::TOKEN, $logs);
        self::assertStringContainsString('email.retry_scheduled', $logs);
        self::assertStringContainsString('email.sent', $logs);
    }

    /** E-4: transient failures retry with backoff, then dead-letter after the bound. */
    public function testRetriesAreBoundedThenDeadLettered(): void
    {
        $id = $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $sender = new ScriptedEmailSender('postmark', array_fill(0, 10, DeliveryFailureKind::Transient));
        $worker = $this->worker($sender);

        $worker->runOnce();
        self::assertSame('pending', $this->outbox->rows[$id]['status']);
        self::assertGreaterThan($this->clock->now(), $this->outbox->rows[$id]['next'], 'Backoff must push the next attempt into the future');
        self::assertSame(0, $worker->runOnce(), 'Not due yet');

        for ($i = 0; $i < 10 && $this->outbox->rows[$id]['status'] !== 'dead'; ++$i) {
            $this->clock->advance('+1 hour');
            $worker->runOnce();
        }

        self::assertSame('dead', $this->outbox->rows[$id]['status']);
        self::assertSame(EmailWorker::MAX_ATTEMPTS, $sender->calls);
        self::assertNull($this->outbox->rows[$id]['payload']);
    }

    public function testRejectedMessagesAreDeadLetteredImmediately(): void
    {
        $id = $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $this->worker(new ScriptedEmailSender('postmark', [DeliveryFailureKind::Rejected]))->runOnce();

        self::assertSame('dead', $this->outbox->rows[$id]['status']);
        self::assertSame(1, $this->outbox->rows[$id]['attempts']);
    }

    /** M-07 end to end: a transient primary failure is delivered by the secondary in the same attempt. */
    public function testFailoverDeliversInTheSameAttempt(): void
    {
        $id = $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $sender = new FailoverEmailSender(new ScriptedEmailSender('postmark', [DeliveryFailureKind::Transient]), new ScriptedEmailSender('resend'));
        $this->worker($sender)->runOnce();

        self::assertSame('sent', $this->outbox->rows[$id]['status']);
        self::assertSame('resend', $this->outbox->rows[$id]['provider']);
    }

    /** M-09: outside production, unlisted recipients are never emailed. */
    public function testNonProductionOnlyEmailsAllowListedRecipients(): void
    {
        $allowed = $this->outbox->enqueue(new EmailAddress('ceo@paxofi.com'), 'verify-email', ['token' => self::TOKEN]);
        $stranger = $this->outbox->enqueue(new EmailAddress('real.customer@gmail.com'), 'verify-email', ['token' => self::TOKEN]);
        $sender = new ScriptedEmailSender();
        $this->worker($sender, RecipientPolicy::fromEnvironment('staging', '@paxofi.com'))->runOnce();

        self::assertSame('sent', $this->outbox->rows[$allowed]['status']);
        self::assertSame('dead', $this->outbox->rows[$stranger]['status']);
        self::assertSame(1, $sender->calls);
        self::assertTrue(RecipientPolicy::fromEnvironment('production', '')->allows(new EmailAddress('anyone@gmail.com')));
        self::assertFalse(RecipientPolicy::fromEnvironment('staging', '')->allows(new EmailAddress('anyone@gmail.com')));
    }

    /** M-01: a sealed payload copied onto another row (another recipient) is refused, not sent. */
    public function testPayloadMovedToAnotherRowIsRefused(): void
    {
        $victim = $this->outbox->enqueue(new EmailAddress('victim@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $attacker = $this->outbox->enqueue(new EmailAddress('attacker@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $this->outbox->movePayload($victim, $attacker);
        $sender = new ScriptedEmailSender();

        $this->worker($sender)->runOnce();

        self::assertSame(0, $sender->calls);
        self::assertSame('dead', $this->outbox->rows[$attacker]['status']);
        self::assertSame('payload-invalid', $this->outbox->rows[$attacker]['error']);
    }

    /** M-06: a global hourly cap stops sending once reached. */
    public function testHourlyCapStopsSending(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->outbox->enqueue(new EmailAddress(sprintf('u%d@paxofi.com', $i)), 'verify-email', ['token' => self::TOKEN]);
        }
        $sender = new ScriptedEmailSender();
        $worker = $this->worker($sender, null, 2);

        $worker->runOnce();
        $worker->runOnce();
        self::assertSame(2, $sender->calls);

        $this->clock->advance('+61 minutes');
        $worker->runOnce();
        self::assertSame(3, $sender->calls);
    }

    public function testUnknownTemplateIsDeadLetteredNotSent(): void
    {
        $id = $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => 'bad']);
        $sender = new ScriptedEmailSender();
        $this->worker($sender)->runOnce();

        self::assertSame(0, $sender->calls);
        self::assertSame('render-failed', $this->outbox->rows[$id]['error']);
    }

    public function testClaimedItemsCarryNoPlaintext(): void
    {
        $this->outbox->enqueue(new EmailAddress('ada@example.ng'), 'verify-email', ['token' => self::TOKEN]);
        $items = $this->outbox->claim(5, $this->clock->now(), 60);

        self::assertCount(1, $items);
        self::assertInstanceOf(OutboxItem::class, $items[0]);
        self::assertStringNotContainsString(self::TOKEN, $items[0]->payload);
        self::assertStringNotContainsString('ada@example.ng', $items[0]->payload);
    }

    private function worker(\PaxofiCloud\Application\Contracts\TransactionalEmailSender $sender, ?RecipientPolicy $policy = null, int $cap = 500): EmailWorker
    {
        return new EmailWorker(
            $this->outbox,
            $this->worker,
            new EmailTemplates('https://cloud-staging.paxofi.com'),
            $sender,
            $policy ?? RecipientPolicy::fromEnvironment('production', ''),
            $this->clock,
            $this->logger,
            $cap,
        );
    }
}
