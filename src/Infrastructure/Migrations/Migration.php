<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

final readonly class Migration
{
    public string $checksum;

    public function __construct(
        public string $version,
        public string $name,
        public string $sql,
    ) {
        $this->checksum = hash('sha256', $sql);
    }

    public function number(): int
    {
        return (int) $this->version;
    }
}
