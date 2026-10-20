<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Pricing;

use PaxofiCloud\Application\Contracts\FxRateSource;
use PaxofiCloud\Application\Pricing\FxRateUpdater;
use PaxofiCloud\Application\Pricing\FxSourceUnavailable;
use PaxofiCloud\Application\Pricing\FxUpdateOutcome;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRatePolicy;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\PricingFixtures;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** PRC-013, CD-16, C-27: accept, hold or report the source as unavailable; never use a held set. */
final class FxRateUpdaterTest extends TestCase
{
    /** @var list<ExchangeRates|FxSourceUnavailable> */
    private array $answers = [];
    private InMemoryFxRateStore $store;
    private RecordingLogger $logger;
    private MutableClock $clock;
    private FxRateUpdater $updater;

    protected function setUp(): void
    {
        $this->store = new InMemoryFxRateStore();
        $this->logger = new RecordingLogger();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-20T06:00:00Z'));
        $answers = &$this->answers;
        $source = new class ($answers) implements FxRateSource {
            /** @param list<ExchangeRates|FxSourceUnavailable> $answers */
            public function __construct(private array &$answers)
            {
            }

            public function fetch(): ExchangeRates
            {
                $answer = array_shift($this->answers) ?? throw new \LogicException('no scripted answer');
                if ($answer instanceof FxSourceUnavailable) {
                    throw $answer;
                }

                return $answer;
            }
        };
        $this->updater = new FxRateUpdater($source, $this->store, FxRatePolicy::defaults(), $this->clock, $this->logger);
    }

    public function testAcceptsThenHoldsALargeMoveAndKeepsUsingTheAcceptedSet(): void
    {
        $this->answers = [PricingFixtures::rates('1500'), PricingFixtures::rates('1600')];

        self::assertSame(FxUpdateOutcome::Accepted, $this->updater->update());
        self::assertSame(FxUpdateOutcome::Held, $this->updater->update());

        self::assertCount(2, $this->store->sets);
        self::assertSame(FxRateVerdict::Held, $this->store->sets[1]->verdict, 'the held set is kept for Finance');
        self::assertSame('1500', $this->store->latestAccepted()?->rates->rate(CostCurrency::NGN)->value, 'and never used');
        self::assertSame('fx.update.held', $this->logger->records[1]['message']);
    }

    public function testASourceFailureRecordsNothing(): void
    {
        $this->answers = [new FxSourceUnavailable('http_503')];

        self::assertSame(FxUpdateOutcome::Unavailable, $this->updater->update());
        self::assertSame([], $this->store->sets);
        self::assertSame(['reason' => 'http_503'], $this->logger->records[0]['context']);
    }

    public function testStaleAfterSeventyTwoHoursWithoutAnAcceptedSet(): void
    {
        self::assertTrue($this->updater->isStale(), 'no rates yet');
        $this->answers = [PricingFixtures::rates('1500')];
        $this->updater->update();
        self::assertFalse($this->updater->isStale());

        $this->clock->advance('+72 hours +1 second');
        self::assertTrue($this->updater->isStale());
    }
}
