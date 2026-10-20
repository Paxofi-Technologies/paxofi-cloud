<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Pricing;

enum FxUpdateOutcome: string
{
    case Accepted = 'accepted';
    case Held = 'held';
    case Unavailable = 'unavailable';
}
