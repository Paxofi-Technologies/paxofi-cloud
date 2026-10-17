<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\TransactionManager;

final class ImmediateTransactions implements TransactionManager
{
    public int $count = 0;
    /** @var list<\Throwable> thrown (once each) at the start of successive transactions */
    public array $failures = [];

    public function transaction(callable $operation): mixed
    {
        $this->count++;
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }

        return $operation();
    }
}
