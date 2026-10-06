<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/** Customer power controls (SRS VPS-004). */
enum PowerAction: string
{
    case Start = 'start';
    case Shutdown = 'shutdown';
    case Reboot = 'reboot';
    case Reset = 'reset';
}
