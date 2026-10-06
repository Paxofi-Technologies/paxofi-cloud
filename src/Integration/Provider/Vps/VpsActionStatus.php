<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

enum VpsActionStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
