<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Registrar\TldCostList;

/**
 * A registrar's full list of extensions with our one-year cost prices
 * (PRC-011, CD-13). Implemented per registrar in the Integration layer and
 * called only by the provider worker, which holds the credential.
 */
interface DomainPriceFeed
{
    /** The provider name used in snapshots and audit events, e.g. "domainnameapi". */
    public function provider(): string;

    /** @throws RegistrarUnavailable when the whole fetch fails; bad items are counted, not thrown */
    public function fetchAll(): TldCostList;
}
