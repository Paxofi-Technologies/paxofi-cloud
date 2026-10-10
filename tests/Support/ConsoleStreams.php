<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Cli\Console;

/** In-memory stdin/stdout/stderr for CLI command tests. */
final class ConsoleStreams
{
    /** @var resource */
    private mixed $out;
    /** @var resource */
    private mixed $err;
    /** @var resource|null */
    private mixed $in = null;

    public function __construct(?string $input = null)
    {
        $this->out = self::memory();
        $this->err = self::memory();
        if ($input !== null) {
            $this->in = self::memory();
            fwrite($this->in, $input);
            rewind($this->in);
        }
    }

    public function console(): Console
    {
        return new Console($this->out, $this->err, $this->in);
    }

    public function output(): string
    {
        return self::contents($this->out);
    }

    public function errors(): string
    {
        return self::contents($this->err);
    }

    /** @return resource */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
