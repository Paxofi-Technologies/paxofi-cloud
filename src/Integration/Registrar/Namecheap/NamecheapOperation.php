<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

use PaxofiCloud\Application\Registrar\RegistrarAction;

/**
 * Every Namecheap command this adapter may send, with the registrar action it
 * performs. A request can only be built from one of these, so a write can
 * never go out under a read's name (DR-4). Writes are added here, each with
 * its write action, when the registration flows need them.
 */
enum NamecheapOperation: string
{
    case GetPricing = 'namecheap.users.getPricing';

    public function action(): RegistrarAction
    {
        return match ($this) {
            self::GetPricing => RegistrarAction::PriceList,
        };
    }
}
