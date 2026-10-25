<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/** A search was refused before any lookup (DS-4, DS-6). */
final class DomainSearchRefused extends \RuntimeException
{
    public const string INVALID_NAME = 'invalid_name';
    public const string UNKNOWN_EXTENSION = 'unknown_extension';
    public const string TOO_MANY = 'too_many_requests';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Domain search refused: ' . $reason);
    }
}
