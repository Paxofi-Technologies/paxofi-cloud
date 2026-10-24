<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Cli;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\DomainCostSync;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Application\Staff\StaffManagerAlerts;
use PaxofiCloud\Cli\PricesStatusCommand;
use PaxofiCloud\Cli\PricesSyncCommand;
use PaxofiCloud\Cli\ProvidersWorkCommand;
use PaxofiCloud\Domain\Registrar\CostQuarantinePolicy;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryProviderSyncRequests;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PHPUnit\Framework\TestCase;

final class PricesCommandsTest extends TestCase
{
    private InMemoryDomainCostStore $store;
    private ScriptedPriceFeed $feed;
    private RecordingLock $lock;

    protected function setUp(): void
    {
        $this->store = new InMemoryDomainCostStore();
        $this->feed = new ScriptedPriceFeed();
        $this->lock = new RecordingLock();
    }

    private function sync(): DomainCostSync
    {
        $logger = new RecordingLogger();
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-24T05:00:00Z'));

        return new DomainCostSync(
            $this->feed,
            $this->store,
            $this->lock,
            new CostQuarantinePolicy(),
            new StaffManagerAlerts(new InMemoryStaffDirectory(), new RecordingOutbox(), $logger),
            new AuditTrail(new InMemoryAuditLog(), $clock, $logger),
            $clock,
            $logger,
            static function (int $seconds): void {
            },
            new InMemoryProviderSyncRequests(),
        );
    }

    public function testSyncPrintsTheOutcomeAndExitsWithItsCode(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081]))->then(new RegistrarUnavailable('unauthorised'));
        $sync = $this->sync();

        $streams = new ConsoleStreams();
        self::assertSame(0, (new PricesSyncCommand(static fn (): DomainCostSync => $sync, $streams->console()))->execute([]));
        self::assertSame("domainnameapi outcome=accepted reason=- attempts=1\n", $streams->output());

        $streams = new ConsoleStreams();
        self::assertSame(1, (new PricesSyncCommand(static fn (): DomainCostSync => $sync, $streams->console()))->execute([]));
        self::assertSame("domainnameapi outcome=failed reason=unauthorised attempts=1\n", $streams->output());
        self::assertSame('cli', $this->store->runs[0]->trigger);
    }

    public function testSyncReportsABusyLockAndMissingConfiguration(): void
    {
        $this->lock->busy = true;
        $sync = $this->sync();
        $streams = new ConsoleStreams();
        self::assertSame(3, (new PricesSyncCommand(static fn (): DomainCostSync => $sync, $streams->console()))->execute([]));

        $streams = new ConsoleStreams();
        $unconfigured = static fn (): DomainCostSync => throw new \InvalidArgumentException('PROVIDER_CREDENTIAL_KEYS is not set.');
        self::assertSame(1, (new PricesSyncCommand($unconfigured, $streams->console()))->execute([]));
        self::assertStringContainsString('not configured', $streams->errors());
    }

    public function testStatusPrintsCountsAndRunsOnly(): void
    {
        $streams = new ConsoleStreams();
        (new PricesStatusCommand($this->store, $streams->console(), 'domainnameapi', 'live'))->execute([]);
        self::assertSame("domainnameapi environment=live accepted_extensions=none\nno price sync has run yet\n", $streams->output());

        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1081, 'ng' => 900]), null, new \DateTimeImmutable('2026-10-24T01:30:00Z'));
        $this->store->recordRun(new SyncRun('domainnameapi', 'schedule', 'failed', 'transport', null, 4, new \DateTimeImmutable('2026-10-24T01:30:00Z'), new \DateTimeImmutable('2026-10-24T01:31:00Z')));
        $streams = new ConsoleStreams();
        (new PricesStatusCommand($this->store, $streams->console(), 'domainnameapi', 'live'))->execute([]);
        self::assertSame(
            "domainnameapi environment=live accepted_extensions=2\n2026-10-24T01:30:00Z trigger=schedule outcome=failed reason=transport attempts=4\n",
            $streams->output(),
        );
    }

    public function testWorkerChecksTheDailySyncButNotInOnceMode(): void
    {
        $intake = static fn (): CredentialIntakeWorker => throw new \InvalidArgumentException('no intake keys');
        $built = 0;
        $prices = function () use (&$built): DomainCostSync {
            $built++;

            return $this->sync();
        };

        $streams = new ConsoleStreams();
        self::assertSame(1, (new ProvidersWorkCommand($intake, $streams->console(), null, $prices))->execute(['--once']));
        self::assertSame(0, $built, 'an unconfigured worker never builds the price sync');
    }
}
