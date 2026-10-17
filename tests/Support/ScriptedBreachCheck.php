<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;

/** Answers with a fixed result and records how often it was asked. */
final class ScriptedBreachCheck implements BreachedPasswordCheck
{
    public int $calls = 0;

    public function __construct(private readonly ?bool $result = false)
    {
    }

    public function isBreached(#[\SensitiveParameter] string $password): ?bool
    {
        $this->calls++;

        return $this->result;
    }
}
