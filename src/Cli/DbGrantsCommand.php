<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Database\GrantsRefused;
use PaxofiCloud\Infrastructure\Database\RuntimeGrants;

/**
 * `bin/paxoficloud db:grants <runtime-user>` — run as the schema owner after
 * `migrate`: resets the runtime user's privileges to RuntimeGrants::POLICY
 * (threat model §6.22). Exit 2 when a table has no policy.
 */
final readonly class DbGrantsCommand implements Command
{
    public function __construct(private RuntimeGrants $grants, private Console $console)
    {
    }

    public function name(): string
    {
        return 'db:grants';
    }

    public function execute(array $arguments): int
    {
        try {
            $result = $this->grants->apply($arguments[0] ?? '');
        } catch (GrantsRefused $e) {
            $this->console->error('db:grants refused: ' . $e->getMessage());

            return 2;
        }
        $this->console->line(sprintf('Runtime grants applied: %d table(s), %d with access.', $result['tables'], $result['granted']));

        return 0;
    }
}
