<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\Logger;

final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function debug(string $message, array $context = []): void
    {
        $this->record('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->record('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->record('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->record('error', $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    private function record(string $level, string $message, array $context): void
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $clean[(string) $key] = $value;
        }
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $clean];
    }
}
