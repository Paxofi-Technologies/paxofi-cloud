<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;

/**
 * `bin/paxoficloud providers:work [--once] [--max-seconds=N]` — the provider
 * worker (THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-6). Today it stores
 * credentials entered in the admin console; the provider sync joins it.
 * Runs for a bounded time and exits so the container restarts it fresh.
 */
final readonly class ProvidersWorkCommand implements Command
{
    /** @param \Closure(): CredentialIntakeWorker $intake built lazily so keys are read only when it runs */
    public function __construct(private \Closure $intake, private Console $console, private ?\Closure $sleep = null)
    {
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
        $deadline = time() + $maxSeconds;
        do {
            $handled = $intake->runOnce();
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
}
