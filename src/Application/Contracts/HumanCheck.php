<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

enum HumanCheck
{
    case Passed;
    /** Missing, expired, reused, or issued for another host or form. */
    case Failed;
    /** The challenge provider could not be asked; fail closed (§6.12). */
    case Unavailable;
}
