<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\WhoisLookup;

/**
 * Availability of one name from port-43 WHOIS (DS-15 to DS-17, DS-19), run
 * only in the provider worker. The server comes from WhoisServerDirectory;
 * each server is asked at most 4 times a second and 30 times a minute across
 * all users, and over that the answer is unknown with no call. Any failure
 * is unknown, never available. Names and replies are never logged.
 */
final readonly class WhoisAvailability
{
    public const int SERVER_PER_SECOND = 4;
    public const int SERVER_PER_MINUTE = 30;

    public function __construct(
        private WhoisLookup $whois,
        private WhoisServerDirectory $servers,
        private RateLimiter $limiter,
    ) {
    }

    /** @return 'available'|'taken'|'unknown' */
    public function check(string $domain, ?string $fixedServer = null, ?string $extraPhrase = null): string
    {
        $tld = explode('.', $domain, 2)[1] ?? '';
        if ($tld === '') {
            return 'unknown';
        }
        $server = $this->servers->serverFor($tld, $fixedServer);
        if ($server === null) {
            return 'unknown';
        }
        $key = hash('sha256', $server);
        if ($this->limiter->hit('whois:server:1:' . $key, self::SERVER_PER_SECOND, 1) > 0
            || $this->limiter->hit('whois:server:60:' . $key, self::SERVER_PER_MINUTE, 60) > 0) {
            return 'unknown';
        }
        try {
            return WhoisReplyReader::read($this->whois->query($server, $domain), $extraPhrase);
        } catch (WhoisUnavailable) {
            return 'unknown';
        }
    }
}
