<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Email\OutboxReport;

/**
 * `bin/paxoficloud mail:status` — outbox counts and the latest delivery
 * problems, so an operator can see why an email did not arrive. Prints no
 * recipients, hashes, tokens or payloads.
 */
final readonly class MailStatusCommand implements Command
{
    private const int PROBLEMS = 5;

    public function __construct(private OutboxReport $report, private Console $console)
    {
    }

    public function name(): string
    {
        return 'mail:status';
    }

    public function execute(array $arguments): int
    {
        $parts = [];
        foreach ($this->report->countsByStatus() as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }
        $this->console->line('outbox ' . implode(' ', $parts));

        $problems = $this->report->recentProblems(self::PROBLEMS);
        if ($problems === []) {
            $this->console->line('no recent delivery problems');

            return 0;
        }
        foreach ($problems as $problem) {
            $this->console->line(sprintf(
                'problem %s %s template=%s attempts=%d error=%s',
                $problem['updated_at'],
                $problem['status'],
                $problem['template'],
                $problem['attempts'],
                self::printable($problem['last_error']),
            ));
        }

        return 0;
    }

    /** Keeps one line per problem whatever a provider put in its error text. */
    private static function printable(string $text): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    }
}
