<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Spaceship;

use PaxofiCloud\Application\Registrar\RegistrarAction;

/**
 * Every Spaceship call this adapter may make, with its method, path and the
 * registrar action it performs. A request can only be built from one of
 * these, so a write can never go out under a read's name (DR-4, SP-T4).
 */
enum SpaceshipOperation: string
{
    case Availability = 'domains_available';

    /** @return array{string, string, RegistrarAction} */
    public function spec(): array
    {
        return match ($this) {
            self::Availability => ['POST', '/domains/available', RegistrarAction::Availability],
        };
    }
}
