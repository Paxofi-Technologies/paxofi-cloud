<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/**
 * A customer SSH public key accepted for server access (SRS VPS-002,
 * threat model D-9/V-11): only ssh-ed25519, or ssh-rsa with a modulus of at
 * least 3072 bits. The key blob is parsed, not pattern-matched, so a key
 * whose declared type disagrees with its contents is rejected. Comments are
 * dropped and never sent to providers.
 */
final class SshPublicKey
{
    private const int MIN_RSA_BITS = 3072;
    private const int MAX_LINE_LENGTH = 8192;

    private function __construct(
        public readonly string $type,
        private readonly string $blob,
        public readonly int $bits,
    ) {
    }

    public static function parse(string $line): self
    {
        $line = trim($line);
        if ($line === '' || strlen($line) > self::MAX_LINE_LENGTH || preg_match('/[\r\n\x00]/', $line) === 1) {
            throw new InvalidSshKey('SSH key must be a single line.');
        }

        $parts = preg_split('/\s+/', $line, 3);
        if ($parts === false || count($parts) < 2) {
            throw new InvalidSshKey('SSH key must be "<type> <base64>".');
        }
        [$type, $encoded] = $parts;

        $blob = base64_decode($encoded, true);
        if ($blob === false || $blob === '') {
            throw new InvalidSshKey('SSH key data is not valid base64.');
        }

        $offset = 0;
        $innerType = self::readString($blob, $offset);
        if ($innerType !== $type) {
            throw new InvalidSshKey('SSH key type does not match its contents.');
        }

        $bits = match ($type) {
            'ssh-ed25519' => self::ed25519Bits($blob, $offset),
            'ssh-rsa' => self::rsaBits($blob, $offset),
            default => throw new InvalidSshKey('Only ssh-ed25519 and ssh-rsa (>= 3072 bits) keys are accepted.'),
        };
        if ($offset !== strlen($blob)) {
            throw new InvalidSshKey('SSH key has trailing data.');
        }
        if ($type === 'ssh-rsa' && $bits < self::MIN_RSA_BITS) {
            throw new InvalidSshKey(sprintf('RSA keys must be at least %d bits; this key has %d.', self::MIN_RSA_BITS, $bits));
        }

        return new self($type, $blob, $bits);
    }

    /** The key in OpenSSH authorized_keys form, without any comment. */
    public function openSsh(): string
    {
        return $this->type . ' ' . base64_encode($this->blob);
    }

    /** OpenSSH-style SHA256 fingerprint, shown to the customer and written to the audit log. */
    public function fingerprint(): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $this->blob, true)), '=');
    }

    private static function ed25519Bits(string $blob, int &$offset): int
    {
        if (strlen(self::readString($blob, $offset)) !== 32) {
            throw new InvalidSshKey('Ed25519 key must be 32 bytes.');
        }

        return 256;
    }

    private static function rsaBits(string $blob, int &$offset): int
    {
        $exponent = ltrim(self::readString($blob, $offset), "\x00");
        $modulus = ltrim(self::readString($blob, $offset), "\x00");
        if ($exponent === '' || $modulus === '') {
            throw new InvalidSshKey('RSA key is missing its exponent or modulus.');
        }

        $leading = ord($modulus[0]);

        return (strlen($modulus) - 1) * 8 + (int) floor(log($leading, 2)) + 1;
    }

    /** Reads one SSH wire-format string (uint32 length + bytes). */
    private static function readString(string $blob, int &$offset): string
    {
        if (strlen($blob) - $offset < 4) {
            throw new InvalidSshKey('SSH key data is truncated.');
        }
        $header = unpack('N', substr($blob, $offset, 4));
        $length = is_array($header) && is_int($header[1] ?? null) ? $header[1] : -1;
        $offset += 4;
        if ($length < 0 || $length > strlen($blob) - $offset) {
            throw new InvalidSshKey('SSH key data is truncated.');
        }
        $value = substr($blob, $offset, $length);
        $offset += $length;

        return $value;
    }
}
