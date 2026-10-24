<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * A domain extension as a registrar sells it, without the leading dot:
 * "com", "com.ng", "xn--p1ai". Lower-case DNS labels only (letters, digits,
 * hyphens; punycode for internationalised extensions), 1-63 characters per
 * label, at most three labels (THREAT-MODEL-DOMAIN-REGISTRAR.md, DR-7).
 */
final readonly class TldName
{
    private const string PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)){0,2}$/';

    public string $value;

    public function __construct(string $value)
    {
        if (strlen($value) > 127 || preg_match(self::PATTERN, $value) !== 1 || preg_match('/(^|\.)[0-9]+$/', $value) === 1) {
            throw new \InvalidArgumentException('Not a domain extension name.');
        }
        $this->value = $value;
    }

    /** Accepts a leading dot and upper case from a provider feed; anything else must already be valid. */
    public static function fromFeed(string $raw): self
    {
        return new self(strtolower(ltrim($raw, '.')));
    }
}
