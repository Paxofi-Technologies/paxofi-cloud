<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Cli;

use PaxofiCloud\Cli\MailStatusCommand;
use PaxofiCloud\Infrastructure\Email\OutboxReport;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PHPUnit\Framework\TestCase;

final class MailStatusCommandTest extends TestCase
{
    public function testPrintsCountsAndOneLinePerProblem(): void
    {
        $report = self::report(['pending' => 1, 'sending' => 0, 'sent' => 4, 'dead' => 1], [
            ['template' => 'verify-email', 'status' => 'dead', 'attempts' => 0, 'last_error' => "suppressed-not-allowlisted", 'updated_at' => '2026-10-17 10:00:00.000000'],
            ['template' => 'verify-email', 'status' => 'pending', 'attempts' => 2, 'last_error' => "smtp: tls\nfailed", 'updated_at' => '2026-10-17 09:00:00.000000'],
        ]);
        $streams = new ConsoleStreams();

        self::assertSame(0, (new MailStatusCommand($report, $streams->console()))->execute([]));
        self::assertSame(
            "outbox pending=1 sending=0 sent=4 dead=1\n"
            . "problem 2026-10-17 10:00:00.000000 dead template=verify-email attempts=0 error=suppressed-not-allowlisted\n"
            . "problem 2026-10-17 09:00:00.000000 pending template=verify-email attempts=2 error=smtp: tls?failed\n",
            $streams->output(),
        );
    }

    public function testSaysSoWhenThereAreNoProblems(): void
    {
        $streams = new ConsoleStreams();
        (new MailStatusCommand(self::report(['pending' => 0, 'sending' => 0, 'sent' => 0, 'dead' => 0], []), $streams->console()))->execute([]);

        self::assertStringContainsString('no recent delivery problems', $streams->output());
    }

    /**
     * @param array<string, int> $counts
     * @param list<array{template: string, status: string, attempts: int, last_error: string, updated_at: string}> $problems
     */
    private static function report(array $counts, array $problems): OutboxReport
    {
        return new readonly class ($counts, $problems) implements OutboxReport {
            /**
             * @param array<string, int> $counts
             * @param list<array{template: string, status: string, attempts: int, last_error: string, updated_at: string}> $problems
             */
            public function __construct(private array $counts, private array $problems)
            {
            }

            public function countsByStatus(): array
            {
                return $this->counts;
            }

            public function recentProblems(int $limit): array
            {
                return array_slice($this->problems, 0, $limit);
            }
        };
    }
}
