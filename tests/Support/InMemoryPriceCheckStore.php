<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\PriceCheckStore;
use PaxofiCloud\Application\Registrar\PriceCheckRun;

final class InMemoryPriceCheckStore implements PriceCheckStore
{
    /** @var list<PriceCheckRun> */
    public array $runs = [];

    public function save(PriceCheckRun $run): void
    {
        $this->runs[] = $run;
    }

    public function latest(): ?PriceCheckRun
    {
        return $this->runs === [] ? null : $this->runs[count($this->runs) - 1];
    }
}
