<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Audit;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditTrailTest extends TestCase
{
    public function testRecordsTheEventWithTheClockTime(): void
    {
        $log = new InMemoryAuditLog();
        $trail = new AuditTrail($log, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), new RecordingLogger());

        $trail->record('auth.sign_in.succeeded', AuditEvent::SUCCESS, ClientContext::ip('198.51.100.7'), 'id-1', 'acct-1', ['method' => 'password']);

        self::assertCount(1, $log->events);
        self::assertSame('2026-10-26 09:00:00', $log->events[0]->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame(['method' => 'password'], $log->events[0]->details);
    }

    public function testAFailedWriteIsLoggedNotThrown(): void
    {
        $log = new InMemoryAuditLog();
        $log->fail = true;
        $logger = new RecordingLogger();
        $trail = new AuditTrail($log, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), $logger);

        $trail->record('auth.signed_out', AuditEvent::SUCCESS, ClientContext::ip('198.51.100.7'), 'id-1');

        self::assertSame([['level' => 'error', 'message' => 'audit.write_failed', 'context' => ['event_type' => 'auth.signed_out', 'error' => \RuntimeException::class]]], $logger->records);
    }

    /** @return iterable<string, array{array<string, string|int|bool>}> */
    public static function freeTextDetails(): iterable
    {
        yield 'an email' => [['email' => 'ada@example.ng']];
        yield 'a sentence' => [['reason' => 'Wrong password for Ada']];
        yield 'a bad key' => [['Reason' => 'x']];
        yield 'too many fields' => [array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], 1)];
    }

    /**
     * T-51: details are fixed codes only, so no personal data or secret can be put there by mistake.
     *
     * @param array<string, string|int|bool> $details
     */
    #[DataProvider('freeTextDetails')]
    public function testDetailsMustBeFixedCodes(array $details): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditEvent('auth.sign_in.failed', AuditEvent::FAILURE, new \DateTimeImmutable(), ClientContext::ip('198.51.100.7'), details: $details);
    }

    public function testOutcomeAndTypeAreConstrained(): void
    {
        foreach ([['Auth Failed', AuditEvent::FAILURE], ['auth.failed', 'maybe']] as [$type, $outcome]) {
            try {
                new AuditEvent($type, $outcome, new \DateTimeImmutable(), ClientContext::ip('198.51.100.7'));
                self::fail('Expected rejection of ' . $type . '/' . $outcome);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
