<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\HealthCheck;
use Paxofi\Core\Observability\HealthResult;
use RuntimeException;

final readonly class StaticHealthCheck implements HealthCheck
{
    public function __construct(private string $name, private ?HealthResult $result, private string $throws = '')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        if ($this->result === null) {
            throw new RuntimeException($this->throws);
        }

        return $this->result;
    }
}
