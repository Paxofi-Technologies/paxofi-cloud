<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Whois;

use PaxofiCloud\Application\Contracts\WhoisLookup;
use PaxofiCloud\Application\Domains\WhoisReplyReader;
use PaxofiCloud\Application\Domains\WhoisUnavailable;
use PaxofiCloud\Integration\Provider\Http\HostResolver;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\SystemHostResolver;

/**
 * Port-43 WHOIS (THREAT-MODEL-DOMAIN-SEARCH.md DS-15, DS-16, DS-T13, DS-T15,
 * DS-T18). The server must be a DNS host name; it is resolved here and every
 * address must be public (the same rules as ProviderHttpClient), then the
 * first address is contacted on port 43 only. The query is a domain or
 * extension in lower-case `a-z 0-9 . -`, so nothing can be injected. 3 s to
 * connect, 5 s in total, 32 KiB read. Nothing is logged here.
 */
final readonly class WhoisClient implements WhoisLookup
{
    public const int CONNECT_TIMEOUT_MS = 3_000;
    public const int TOTAL_TIMEOUT_MS = 5_000;
    public const int MAX_REPLY_BYTES = 32 * 1024;
    private const string QUERY = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public function __construct(
        private WhoisSocket $socket = new StreamWhoisSocket(),
        private HostResolver $resolver = new SystemHostResolver(),
    ) {
    }

    public function query(string $server, string $query): string
    {
        if (!WhoisReplyReader::isValidServer($server)) {
            throw new WhoisUnavailable(WhoisUnavailable::INVALID_SERVER);
        }
        if (preg_match(self::QUERY, $query) !== 1) {
            throw new WhoisUnavailable(WhoisUnavailable::INVALID_QUERY);
        }
        $addresses = $this->resolver->resolve($server);
        if ($addresses === []) {
            throw new WhoisUnavailable(WhoisUnavailable::NOT_RESOLVED);
        }
        foreach ($addresses as $ip) {
            if (!ProviderHttpClient::isPublicAddress($ip)) {
                throw new WhoisUnavailable(WhoisUnavailable::NOT_PUBLIC);
            }
        }
        $reply = $this->socket->exchange($addresses[0], $query . "\r\n", self::CONNECT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS, self::MAX_REPLY_BYTES);
        if (trim($reply) === '') {
            throw new WhoisUnavailable(WhoisUnavailable::EMPTY);
        }

        return $reply;
    }
}
