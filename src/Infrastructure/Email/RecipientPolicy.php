<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

use PaxofiCloud\Application\Email\EmailAddress;

/**
 * Outside production the worker only delivers to allow-listed recipients, so
 * staging and development can never email real customers (threat model E-9).
 * Entries are full addresses or "@domain".
 */
final readonly class RecipientPolicy
{
    /** @param list<string> $allowed lower-cased addresses and @domains; null means everyone */
    private function __construct(private ?array $allowed)
    {
    }

    public static function fromEnvironment(string $appEnv, string $allowList): self
    {
        if ($appEnv === 'production') {
            return new self(null);
        }
        $entries = [];
        foreach (explode(',', $allowList) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return new self($entries);
    }

    public function allows(EmailAddress $to): bool
    {
        if ($this->allowed === null) {
            return true;
        }
        $address = strtolower($to->value);

        return in_array($address, $this->allowed, true) || in_array('@' . $to->domain(), $this->allowed, true);
    }
}
