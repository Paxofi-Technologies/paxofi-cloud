<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Email\EmailWorker;

/**
 * `bin/paxoficloud mail:work [--once] [--max-seconds=N]` — sends queued email.
 * Runs for a bounded time (default one hour) and exits so the container
 * restarts it with fresh memory and configuration.
 */
final readonly class MailWorkCommand implements Command
{
    /** @param \Closure(): EmailWorker $worker built lazily so secrets are read only when it runs */
    public function __construct(private \Closure $worker, private Console $console, private ?\Closure $sleep = null)
    {
    }

    public function name(): string
    {
        return 'mail:work';
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
            $worker = ($this->worker)();
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Mail worker is not configured: ' . $e->getMessage());

            return 1;
        }

        $sleep = $this->sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $deadline = time() + $maxSeconds;
        do {
            $processed = $worker->runOnce();
            if ($once) {
                $this->console->line(sprintf('Processed %d email(s).', $processed));

                return 0;
            }
            if ($processed === 0) {
                $sleep(2);
            }
        } while (time() < $deadline);

        return 0;
    }
}
