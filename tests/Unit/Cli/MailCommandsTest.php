<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Cli;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Cli\MailKeygenCommand;
use PaxofiCloud\Cli\MailWorkCommand;
use PaxofiCloud\Infrastructure\Email\EmailWorker;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Email\RecipientPolicy;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PaxofiCloud\Tests\Support\InMemoryOutbox;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\ScriptedEmailSender;
use PHPUnit\Framework\TestCase;

final class MailCommandsTest extends TestCase
{
    public function testKeygenPrintsAMatchingKeyPair(): void
    {
        $streams = new ConsoleStreams();
        self::assertSame(0, (new MailKeygenCommand($streams->console()))->execute([]));

        self::assertSame(1, preg_match('/^EMAIL_OUTBOX_PUBLIC_KEY=(\S+)\nEMAIL_OUTBOX_SECRET_KEY=(\S+)\n$/', $streams->output(), $m));
        \assert(isset($m[1], $m[2]));
        $sealer = OutboxSealer::forOpening($m[1], $m[2]);
        self::assertSame('hello', $sealer->open($sealer->seal('hello')));
    }

    public function testWorkOnceProcessesTheQueueAndExits(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00 UTC'));
        $outbox = new InMemoryOutbox(OutboxSealer::forSealing($pair['public']), $clock);
        $outbox->enqueue(new EmailAddress('ada@paxofi.com'), 'account-exists', []);
        $sender = new ScriptedEmailSender();
        $worker = new EmailWorker($outbox, OutboxSealer::forOpening($pair['public'], $pair['secret']), new EmailTemplates('https://cloud-staging.paxofi.com'), $sender, RecipientPolicy::fromEnvironment('production', ''), $clock, new RecordingLogger());
        $streams = new ConsoleStreams();

        self::assertSame(0, (new MailWorkCommand(static fn (): EmailWorker => $worker, $streams->console()))->execute(['--once']));
        self::assertSame(1, $sender->calls);
        self::assertStringContainsString('Processed 1 email(s).', $streams->output());
    }

    public function testMissingConfigurationFailsWithoutLeakingSecrets(): void
    {
        $streams = new ConsoleStreams();
        $command = new MailWorkCommand(static fn (): EmailWorker => throw new \InvalidArgumentException('EMAIL_OUTBOX_SECRET_KEY must be set for the mail worker.'), $streams->console());

        self::assertSame(1, $command->execute(['--once']));
        self::assertStringContainsString('Mail worker is not configured', $streams->errors());
    }
}
