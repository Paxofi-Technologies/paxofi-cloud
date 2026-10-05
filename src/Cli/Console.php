<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

/** Minimal output for CLI commands; streams are injectable for tests. */
final readonly class Console
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private mixed $out, private mixed $err)
    {
    }

    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    public function line(string $message = ''): void
    {
        fwrite($this->out, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->err, $message . PHP_EOL);
    }
}
