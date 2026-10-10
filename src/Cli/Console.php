<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

/** Minimal input/output for CLI commands; streams are injectable for tests. */
final readonly class Console
{
    /** Longest secret accepted on standard input (the column holds 4 KiB of ciphertext). */
    public const int MAX_SECRET_BYTES = 2048;

    /**
     * @param resource $out
     * @param resource $err
     * @param resource|null $in
     */
    public function __construct(private mixed $out, private mixed $err, private mixed $in = null)
    {
    }

    public static function standard(): self
    {
        return new self(STDOUT, STDERR, STDIN);
    }

    public function line(string $message = ''): void
    {
        fwrite($this->out, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->err, $message . PHP_EOL);
    }

    /**
     * Reads one secret from standard input. Secrets are never accepted as
     * command-line arguments (they would leak into `ps`, shell history and
     * process accounting), and an interactive terminal is refused so the
     * secret is never echoed on screen.
     *
     * @throws \UnexpectedValueException with a message that never contains the input
     */
    public function readSecret(): string
    {
        if ($this->in === null) {
            throw new \UnexpectedValueException('No standard input is available.');
        }
        if (stream_isatty($this->in)) {
            throw new \UnexpectedValueException('Refusing to read a secret from an interactive terminal; pipe it in instead.');
        }

        $raw = stream_get_contents($this->in, self::MAX_SECRET_BYTES + 3);
        if (!is_string($raw)) {
            throw new \UnexpectedValueException('Could not read standard input.');
        }
        // Allow exactly one trailing line ending, as written by `printf '%s\n'` or a here-string.
        $secret = (string) preg_replace('/\r?\n\z/', '', $raw);
        sodium_memzero($raw);

        if ($secret === '') {
            throw new \UnexpectedValueException('The secret on standard input is empty.');
        }
        if (strlen($secret) > self::MAX_SECRET_BYTES) {
            throw new \UnexpectedValueException(sprintf('The secret is longer than %d bytes.', self::MAX_SECRET_BYTES));
        }
        if (preg_match('/[^\x21-\x7e]/', $secret) === 1) {
            throw new \UnexpectedValueException('The secret contains whitespace, control or non-ASCII characters.');
        }

        return $secret;
    }
}
