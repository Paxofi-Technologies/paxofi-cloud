<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email;

use PaxofiCloud\Application\Email\EmailAddress;

/** The From identity, e.g. `PaxofiCloud <no-reply@paxofi.com>` (threat model E-11). */
final readonly class Sender
{
    public function __construct(public string $name, public EmailAddress $address)
    {
        if (preg_match('/^[A-Za-z0-9 .\'&-]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Sender name must be 1-64 plain characters.');
        }
    }

    public function header(): string
    {
        return sprintf('%s <%s>', $this->name, $this->address->value);
    }
}
