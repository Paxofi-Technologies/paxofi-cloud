<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/** Provider-neutral server state. Anything a provider reports that we do not recognise maps to Unknown. */
enum VpsServerStatus: string
{
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Off = 'off';
    case Transitioning = 'transitioning';
    case Deleting = 'deleting';
    case Unknown = 'unknown';
}
