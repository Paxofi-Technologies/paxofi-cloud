<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Pricing\FxSourceUnavailable;
use PaxofiCloud\Domain\Pricing\ExchangeRates;

/** A replaceable exchange-rate source (commerce threat model CD-16, PRC-013). */
interface FxRateSource
{
    /** @throws FxSourceUnavailable when the source cannot be reached or its answer is not usable */
    public function fetch(): ExchangeRates;
}
