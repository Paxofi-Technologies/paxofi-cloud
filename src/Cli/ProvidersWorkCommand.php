<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Pricing\FxRateUpdater;
use PaxofiCloud\Application\Registrar\DomainCostSync;
use PaxofiCloud\Application\Registrar\PriceCheckRun;
use PaxofiCloud\Application\Registrar\SpaceshipPriceCheck;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;

/**
 * `bin/paxoficloud providers:work [--once] [--max-seconds=N]` — the provider
 * worker (THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-6). Today it stores
 * credentials entered in the admin console, runs a registrar's domain cost
 * sync when the admin console asks for one ("Sync now", DR-12), and about
 * once a minute checks whether each registrar's daily sync is due (02:30 WAT;
 * DR-8). The list of registrars is rebuilt at each of those checks, so a
 * registrar whose connection was just entered joins within a minute (NC-9).
 * It refreshes exchange rates once they are 20 hours old (tried at most
 * hourly), and runs a Spaceship price check when the admin console asks for one
 * (SP-3), once a Spaceship connection exists.
 * Runs for a bounded time and exits so the container restarts it fresh. A
 * failing price sync never stops credential intake or another registrar's.
 */
final readonly class ProvidersWorkCommand implements Command
{
    private const int SCHEDULE_CHECK_SECONDS = 60;
    private const int FX_MAX_AGE_HOURS = 20;

    /**
     * @param \Closure(): CredentialIntakeWorker $intake built lazily so keys are read only when it runs
     * @param (\Closure(): list<DomainCostSync>)|null $prices one price sync per active registrar, also built lazily
     * @param (\Closure(int): void)|null $sleep
     * @param (\Closure(): ?SpaceshipPriceCheck)|null $spaceship the price check, or null while Spaceship is not connected
     * @param (\Closure(): FxRateUpdater)|null $fx exchange rates, refreshed when the accepted set is a day old (DX-1, DX-5)
     */
    public function __construct(
        private \Closure $intake,
        private Console $console,
        private ?\Closure $sleep = null,
        private ?\Closure $prices = null,
        private ?\Closure $spaceship = null,
        private ?\Closure $fx = null,
    ) {
    }

    public function name(): string
    {
        return 'providers:work';
    }

    public function execute(array $arguments): int
    {
        $once = in_array('--once', $arguments, true);
        $maxSeconds = 3600;
        foreach ($arguments as $argument) {
            if (preg_match('/^--max-seconds=(\d{1,6})$/', (string) $argument, $m) === 1) {
                $maxSeconds = max(1, (int) $m[1]);
            }
        }

        try {
            $intake = ($this->intake)();
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Provider worker is not configured: ' . $e->getMessage());

            return 1;
        }

        $sleep = $this->sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $prices = [];
        $check = null;
        $fxTriedAt = 0;
        $deadline = time() + $maxSeconds;
        $nextScheduleCheck = 0;
        do {
            $handled = $intake->runOnce();
            if ($this->prices !== null && !$once) {
                $due = time() >= $nextScheduleCheck;
                if ($due) {
                    $nextScheduleCheck = time() + self::SCHEDULE_CHECK_SECONDS;
                    $prices = $this->pricesSyncs();
                    $check = $this->spaceshipCheck();
                    // At most one exchange-rate attempt per hour, whether it worked or not.
                    if ($this->fx !== null && time() - $fxTriedAt >= 3600) {
                        $fxTriedAt = time();
                        $this->refreshRates();
                    }
                }
                if ($check !== null) {
                    $this->runCheck($check);
                }
                foreach ($prices as $sync) {
                    $this->runPrices(static fn (): ?SyncRun => $sync->runIfRequested());
                    if ($due) {
                        $this->runPrices(static fn (): ?SyncRun => $sync->runIfDue());
                    }
                }
            }
            if ($once) {
                $this->console->line(sprintf('Handled %d credential submission(s).', $handled));

                return 0;
            }
            if ($handled === 0) {
                $sleep(3);
            }
        } while (time() < $deadline);

        return 0;
    }

    /** @return list<DomainCostSync> */
    private function pricesSyncs(): array
    {
        if ($this->prices === null) {
            return [];
        }
        try {
            return ($this->prices)();
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Price sync is not configured: ' . $e->getMessage());

            return [];
        } catch (\Throwable $e) {
            $this->console->error('Price sync could not be prepared: ' . $e::class);

            return [];
        }
    }

    private function refreshRates(): void
    {
        try {
            $outcome = $this->fx === null ? null : ($this->fx)()->refreshIfOlderThan(self::FX_MAX_AGE_HOURS);
            if ($outcome !== null) {
                $this->console->line('Exchange rates: ' . $outcome->value . '.');
            }
        } catch (\Throwable $e) {
            $this->console->error('Exchange rate update error: ' . $e::class);
        }
    }

    private function spaceshipCheck(): ?SpaceshipPriceCheck
    {
        if ($this->spaceship === null) {
            return null;
        }
        try {
            return ($this->spaceship)();
        } catch (\Throwable $e) {
            $this->console->error('Spaceship price check could not be prepared: ' . $e::class);

            return null;
        }
    }

    private function runCheck(SpaceshipPriceCheck $check): void
    {
        try {
            $run = $check->runIfRequested();
            if ($run instanceof PriceCheckRun) {
                $this->console->line(sprintf('Spaceship price check: %s, %d of %d priced.', $run->failure ?? 'done', $run->priced(), $run->asked));
            }
        } catch (\Throwable $e) {
            $this->console->error('Spaceship price check error: ' . $e::class);
        }
    }

    /** @param \Closure(): ?SyncRun $sync */
    private function runPrices(\Closure $sync): void
    {
        try {
            $run = $sync();
            if ($run !== null) {
                $this->console->line(sprintf('Price sync %s (%s): %s%s.', $run->provider, $run->trigger, $run->outcome, $run->reason === null ? '' : ' (' . $run->reason . ')'));
            }
        } catch (\Throwable $e) {
            $this->console->error('Price sync error: ' . $e::class);
        }
    }
}
