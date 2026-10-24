<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Providers;

/**
 * One input a provider connection needs: a login name shown back to staff, or
 * a secret that never is. A field may also fix the exact format it accepts,
 * for example an IPv4 address.
 */
final readonly class ConnectionField
{
    public function __construct(public string $name, public string $label, public bool $secret, public int $maxLength, public ?string $pattern = null)
    {
    }
}
