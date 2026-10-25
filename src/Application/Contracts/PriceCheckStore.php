<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Registrar\PriceCheckRun;

/** Spaceship price checks (migration 0019, SP-7). Written once, never edited. */
interface PriceCheckStore
{
    public function save(PriceCheckRun $run): void;

    /** The newest check, with its items in extension order; null if none has run. */
    public function latest(): ?PriceCheckRun;
}
