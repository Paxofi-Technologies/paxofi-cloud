<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

use PaxofiCloud\Application\Registrar\RegistrarAction;

/**
 * Every Dynadot call this adapter may make, with its method, path and the
 * registrar action it performs. A request can only be built from one of
 * these, so a write can never go out under a read's name (DR-4).
 */
enum DynadotOperation: string
{
    case TldPrice = 'get_tld_price';

    /** @return array{string, string, RegistrarAction} */
    public function spec(): array
    {
        return match ($this) {
            self::TldPrice => ['GET', '/domains/get_tld_price', RegistrarAction::PriceList],
        };
    }
}
