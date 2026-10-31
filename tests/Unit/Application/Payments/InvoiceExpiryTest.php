<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Payments\InvoiceExpiry;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryInvoiceExpiryStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PAYMENTS.md PY-16; SRS CRT-006, ORD-006. */
final class InvoiceExpiryTest extends TestCase
{
    private const string ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private InMemoryInvoiceExpiryStore $invoices;
    private InMemoryAuditLog $audit;
    private RecordingLogger $logger;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->invoices = new InMemoryInvoiceExpiryStore();
        $this->audit = new InMemoryAuditLog();
        $this->logger = new RecordingLogger();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-11-03T10:00:00Z'));
    }

    private function expiry(): InvoiceExpiry
    {
        return new InvoiceExpiry($this->invoices, AuditFixtures::trail($this->audit), $this->clock, $this->logger);
    }

    public function testAnUnpaidInvoicePastExpiryIsCancelledReversedAndAudited(): void
    {
        $this->invoices->add(str_repeat('d', 32), str_repeat('c', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-03T09:59:59Z'));

        self::assertSame(1, $this->expiry()->runOnce());
        self::assertSame('cancelled', $this->invoices->invoices[str_repeat('d', 32)]['status']);
        self::assertSame([str_repeat('d', 32)], $this->invoices->reversed);
        self::assertSame([AuditEventType::ORDER_EXPIRED], $this->audit->types());
        self::assertSame(0, $this->expiry()->runOnce(), 'an invoice is expired once');
    }

    public function testAnInvoiceNotYetDueOrWithAnOpenAttemptIsLeftAlone(): void
    {
        $this->invoices->add(str_repeat('d', 32), str_repeat('c', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-03T10:00:01Z'));
        $this->invoices->add(str_repeat('e', 32), str_repeat('b', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-01T10:00:00Z'), openAttempt: true);

        self::assertSame(0, $this->expiry()->runOnce());
        self::assertSame([], $this->invoices->reversed);
        self::assertSame([], $this->audit->types());
    }

    public function testAPaymentThatSettlesFirstWins(): void
    {
        $this->invoices->add(str_repeat('d', 32), str_repeat('c', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $this->invoices->paidConcurrently = str_repeat('d', 32);

        self::assertSame(0, $this->expiry()->runOnce());
        self::assertSame('paid', $this->invoices->invoices[str_repeat('d', 32)]['status']);
        self::assertSame([], $this->audit->types());
    }

    public function testAFailedExpiryIsLoggedAndTheRestContinue(): void
    {
        $this->invoices->add(str_repeat('d', 32), str_repeat('c', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $this->invoices->add(str_repeat('e', 32), str_repeat('b', 32), self::ACCOUNT, new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $this->invoices->failNext = true;

        self::assertSame(1, $this->expiry()->runOnce());
        self::assertSame('unpaid', $this->invoices->invoices[str_repeat('d', 32)]['status']);
        self::assertSame('cancelled', $this->invoices->invoices[str_repeat('e', 32)]['status']);
        self::assertSame(1, $this->expiry()->runOnce(), 'the next run retries');
    }
}
