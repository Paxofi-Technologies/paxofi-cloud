<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/** A WHOIS query that gave no usable reply; the reason is a fixed code that is safe to log (DS-20). */
final class WhoisUnavailable extends \RuntimeException
{
    public const string INVALID_SERVER = 'whois.invalid_server';
    public const string INVALID_QUERY = 'whois.invalid_query';
    public const string NOT_RESOLVED = 'whois.not_resolved';
    public const string NOT_PUBLIC = 'whois.not_public';
    public const string CONNECT_FAILED = 'whois.connect_failed';
    public const string TIMEOUT = 'whois.timeout';
    public const string EMPTY = 'whois.empty';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
