<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** The country already has a tax row starting at that time (CD-28). */
final class DuplicateTaxRate extends \RuntimeException
{
}
