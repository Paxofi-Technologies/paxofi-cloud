<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Payments\CheckoutStarter;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;

/**
 * `bin/paxoficloud payments:work [--once] [--max-seconds=N]` — the payments
 * worker (THREAT-MODEL-PAYMENTS.md PY-3). It is the only process with the
 * payments intake secret key and the payments key ring, so it is the only one
 * that can store or use a payment gateway key. It stores gateway keys
 * entered in the admin console and starts hosted checkouts for new payment
 * attempts (PY-6); verification joins it in the next step. It looks for work
 * every second while idle, so a checkout page is usually ready within two
 * seconds of the customer's click. Runs for a bounded time and exits so the
 * container restarts it fresh. A failing checkout step never stops intake.
 */
final readonly class PaymentsWorkCommand implements Command
{
    /**
     * @param \Closure(): CredentialIntakeWorker $intake built lazily so keys are read only when it runs
     * @param (\Closure(int): void)|null $sleep
     * @param (\Closure(): CheckoutStarter)|null $checkouts built lazily, like the intake
     */
    public function __construct(
        private \Closure $intake,
        private Console $console,
        private ?\Closure $sleep = null,
        private ?\Closure $checkouts = null,
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
            $checkouts = $this->checkouts !== null ? ($this->checkouts)() : null;
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
            $started = 0;
            try {
                $started = $checkouts?->runOnce() ?? 0;
            } catch (\Throwable $e) {
                // A database hiccup must not stop credential intake; the next loop tries again.
                $this->console->error('Checkout step failed: ' . $e::class);
            }
            if ($once) {
                $this->console->line(sprintf('Handled %d gateway credential submission(s) and %d payment start(s).', $handled, $started));

                return 0;
            }
            if ($handled === 0 && $started === 0) {
                $sleep(1);
            }
        } while (time() < $deadline);

        return 0;
    }
}
