<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Cart\Cart;

interface CartReader
{
    public function getForTenant(string $cartId, RequestContext $context): ?Cart;
}
