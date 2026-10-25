<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Contracts\DomainAvailabilityCheck;
use PaxofiCloud\Application\Registrar\AvailabilityAnswer;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\SpaceshipPriceCheck;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryPriceCheckStore;
use PaxofiCloud\Tests\Support\InMemoryProviderSyncRequests;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7 / SP-T4. */
final class SpaceshipPriceCheckTest extends TestCase
{
    private InMemoryPriceCheckStore $store;
    private InMemoryProviderSyncRequests $requests;
    private InMemoryAuditLog $audit;
    /** @var list<list<string>> */
    private array $asked = [];
    /** @var \Closure(list<string>): list<AvailabilityAnswer> */
    private \Closure $reply;
    private int $labels = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryPriceCheckStore();
        $this->requests = new InMemoryProviderSyncRequests();
        $this->audit = new InMemoryAuditLog();
        $this->reply = static fn (array $names): array => [];
    }

    private function check(): SpaceshipPriceCheck
    {
        $registrar = new class ($this) implements DomainAvailabilityCheck {
            public function __construct(private SpaceshipPriceCheckTest $test)
            {
            }

            public function provider(): string
            {
                return 'spaceship';
            }

            public function check(array $names): array
            {
                return $this->test->answer($names);
            }
        };

        return new SpaceshipPriceCheck(
            $registrar,
            $this->store,
            $this->requests,
            AuditFixtures::trail($this->audit),
            new MutableClock(new \DateTimeImmutable('2026-10-25T03:00:00Z')),
            new RecordingLogger(),
            fn (): string => sprintf('%016x', ++$this->labels),
        );
    }

    /**
     * @param list<string> $names
     * @return list<AvailabilityAnswer>
     */
    public function answer(array $names): array
    {
        $this->asked[] = $names;

        return ($this->reply)($names);
    }

    public function testOneRequestOfTwentyRandomOrdinaryNamesOnePerExtension(): void
    {
        self::assertCount(SpaceshipPriceCheck::PROBE_TLDS_COUNT, SpaceshipPriceCheck::PROBE_TLDS);
        self::assertCount(DomainAvailabilityCheck::MAX_NAMES, SpaceshipPriceCheck::PROBE_TLDS, 'SP-T4: one request per check');
        $this->reply = static function (array $names): array {
            /** @var list<string> $names */
            return [
                new AvailabilityAnswer($names[0], 'available', CostAmount::fromDecimalText(CostCurrency::USD, '9.98'), ['renew']),
                new AvailabilityAnswer($names[19], 'available', null, []),
            ];
        };

        $run = $this->check()->run('cli');

        self::assertCount(1, $this->asked);
        self::assertCount(20, $this->asked[0]);
        self::assertSame('paxofi-price-check-0000000000000001.com', $this->asked[0][0]);
        self::assertSame('paxofi-price-check-0000000000000014.com.ng', $this->asked[0][19]);
        self::assertNull($run->failure);
        self::assertSame(20, $run->asked);
        self::assertSame(2, $run->answered());
        self::assertSame(1, $run->priced());
        self::assertSame('com', $run->items[0]->tld->value);
        self::assertSame(998, $run->items[0]->register?->minor);
        self::assertSame(['renew'], $run->items[0]->otherPriced);
        self::assertSame('missing', $run->items[1]->result, 'a name missing from the answer is recorded as missing');
        self::assertSame([$run], $this->store->runs);
        self::assertSame([AuditEventType::PROVIDER_PRICES_CHECKED], $this->audit->types());
    }

    public function testAFailureIsStoredWithItsReasonOnly(): void
    {
        $this->reply = static fn (array $names): array => throw new RegistrarUnavailable('unauthorised');

        $run = $this->check()->run('cli');

        self::assertSame('unauthorised', $run->failure);
        self::assertSame([], $run->items);
        self::assertSame([$run], $this->store->runs);
        self::assertSame([AuditEventType::PROVIDER_PRICES_CHECK_FAILED], $this->audit->types());
    }

    public function testRunsOnlyWhenTheAdminConsoleAskedAndOnceForManyRequests(): void
    {
        $check = $this->check();
        self::assertNull($check->runIfRequested());
        self::assertSame([], $this->asked);

        $this->requests->add('spaceship', 'c0000000000000000000000000000000', new \DateTimeImmutable('2026-10-25T02:59:00Z'));
        $this->requests->add('spaceship', 'c0000000000000000000000000000000', new \DateTimeImmutable('2026-10-25T02:59:30Z'));
        $this->requests->add('namecheap', 'c0000000000000000000000000000000', new \DateTimeImmutable('2026-10-25T02:59:30Z'));

        $run = $check->runIfRequested();
        self::assertNotNull($run);
        self::assertSame('manual', $run->trigger);
        self::assertNull($check->runIfRequested(), 'both Spaceship requests were claimed by one run');
        self::assertCount(1, $this->asked);
        self::assertNull($this->requests->pendingSince('spaceship'));
        self::assertNotNull($this->requests->pendingSince('namecheap'), 'other registrars\' requests are left alone');
    }

    public function testALabelThatIsNotSixteenHexCharactersStopsTheCheck(): void
    {
        $check = new SpaceshipPriceCheck(
            new class implements DomainAvailabilityCheck {
                public function provider(): string
                {
                    return 'spaceship';
                }

                public function check(array $names): array
                {
                    throw new \LogicException('must not be called');
                }
            },
            $this->store,
            $this->requests,
            AuditFixtures::trail($this->audit),
            new MutableClock(new \DateTimeImmutable('2026-10-25T03:00:00Z')),
            new RecordingLogger(),
            static fn (): string => 'Not/Hex',
        );
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('16 lower-case hex');
        $check->run('cli');
    }
}
