<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/** A registrar write was refused because writes are disabled here (DR-4). */
final class RegistrarWritesDisabled extends \RuntimeException
{
    public function __construct(public readonly RegistrarAction $action)
    {
        parent::__construct('Registrar writes are disabled in this environment.');
    }
}
