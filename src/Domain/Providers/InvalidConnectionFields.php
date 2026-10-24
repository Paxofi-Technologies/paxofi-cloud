<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Providers;

/** The message is a field name or a fixed code, never a submitted value (PC-T6). */
final class InvalidConnectionFields extends \InvalidArgumentException
{
}
