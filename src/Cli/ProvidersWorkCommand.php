<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Registrar\DomainCostSync;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;

/**
 * `bin/paxoficloud providers:work [--once] [--max-seconds=N]` — the provider
 * worker (THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-6). Today it stores
 * credentials entered in the admin console, and about once a minute checks
 * whether the daily domain cost sync is due (02:30 WAT; DR-8). Runs for a
 * bounded time and exits so the container restarts it fresh. A failing
 * price sync never stops credential intake.
 */
final readonly class ProvidersWorkCommand implements Command
{
    private const int SCHEDULE_CHECK_SECONDS = 60;

    /**
     * @param \Closure(): CredentialIntakeWorker $intake built lazily so keys are read only when it runs
     * @param (\Closure(): DomainCostSync)|null $prices the daily price sync, also built lazily
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        private \Closure $intake,
        private Console $console,
        private ?\Closure $sleep = null,
        private ?\Closure $prices = null,
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
        $prices = $this->pricesSync();
        $deadline = time() + $maxSeconds;
        $nextScheduleCheck = 0;
        do {
            $handled = $intake->runOnce();
            if ($prices !== null && !$once && time() >= $nextScheduleCheck) {
                $nextScheduleCheck = time() + self::SCHEDULE_CHECK_SECONDS;
                $this->runDuePrices($prices);
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

    private function pricesSync(): ?DomainCostSync
    {
        if ($this->prices === null) {
            return null;
        }
        try {
            return ($this->prices)();
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Price sync is not configured: ' . $e->getMessage());

            return null;
        }
    }

    private function runDuePrices(DomainCostSync $prices): void
    {
        try {
            $run = $prices->runIfDue();
            if ($run !== null) {
                $this->console->line(sprintf('Price sync %s: %s%s.', $run->provider, $run->outcome, $run->reason === null ? '' : ' (' . $run->reason . ')'));
            }
        } catch (\Throwable $e) {
            $this->console->error('Price sync error: ' . $e::class);
        }
    }
}
