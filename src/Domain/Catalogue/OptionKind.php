<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

enum OptionKind: string
{
    case Choice = 'enum';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
