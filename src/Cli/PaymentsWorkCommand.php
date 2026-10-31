<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;

/**
 * `bin/paxoficloud payments:work [--once] [--max-seconds=N]` — the payments
 * worker (THREAT-MODEL-PAYMENTS.md PY-3). It is the only process with the
 * payments intake secret key and the payments key ring, so it is the only one
 * that can store or use a payment gateway key. Today it stores gateway keys
 * entered in the admin console; payment initialisation and verification join
 * it in the next steps. Runs for a bounded time and exits so the container
 * restarts it fresh.
 */
final readonly class PaymentsWorkCommand implements Command
{
    /**
     * @param \Closure(): CredentialIntakeWorker $intake built lazily so keys are read only when it runs
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        private \Closure $intake,
        private Console $console,
        private ?\Closure $sleep = null,
    ) {
    }

    public function name(): string
    {
        return 'payments:work';
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
            $this->console->error('Payments worker is not configured: ' . $e->getMessage());

            return 1;
        }

        $sleep = $this->sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $deadline = time() + $maxSeconds;
        do {
            $handled = $intake->runOnce();
            if ($once) {
                $this->console->line(sprintf('Handled %d gateway credential submission(s).', $handled));

                return 0;
            }
            if ($handled === 0) {
                $sleep(3);
            }
        } while (time() < $deadline);

        return 0;
    }
}
