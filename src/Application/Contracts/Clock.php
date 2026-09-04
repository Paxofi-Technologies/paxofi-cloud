<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
