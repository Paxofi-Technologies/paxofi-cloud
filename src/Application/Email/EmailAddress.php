<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

/**
 * A validated recipient address. The domain is lower-cased; the local part is
 * kept as given (it may be case-sensitive). Header-injection characters are
 * impossible by construction.
 */
final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (strlen($value) > 254 || preg_match('/[\x00-\x1f\x7f,;<>"\s]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Not a valid email address.');
        }
        $at = strrpos($value, '@');
        \assert($at !== false);
        $this->value = substr($value, 0, $at) . '@' . strtolower(substr($value, $at + 1));
    }

    public function domain(): string
    {
        return substr($this->value, (int) strrpos($this->value, '@') + 1);
    }

    /** Stable, non-reversible reference for logs and de-duplication (threat model E-10). */
    public function hash(): string
    {
        return hash('sha256', strtolower($this->value));
    }
}
