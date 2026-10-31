<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\WhoisLookup;

/**
 * Which WHOIS server answers for an extension (DS-15). A server staff fixed
 * for the extension wins; otherwise IANA is asked once (`whois.iana.org`,
 * the `refer:` line) for the top-level zone, so `com.ng` uses the `ng`
 * server. A learned server is trusted for 7 days and the last good one is
 * kept when IANA cannot be reached; a zone IANA names no server for is
 * remembered for a day. IANA is asked at most 10 times an hour in total.
 */
final readonly class WhoisServerDirectory
{
    public const string IANA = 'whois.iana.org';
    public const int FRESH_SECONDS = 7 * 86_400;
    public const int NONE_SECONDS = 86_400;
    public const int IANA_PER_HOUR = 10;

    public function __construct(private WhoisLookup $whois, private RedisClient $redis, private RateLimiter $limiter)
    {
    }

    public static function zone(string $tld): string
    {
        $parts = explode('.', strtolower($tld));

        return (string) end($parts);
    }

    public function serverFor(string $tld, ?string $fixedServer = null): ?string
    {
        if ($fixedServer !== null && WhoisReplyReader::isValidServer($fixedServer)) {
            return $fixedServer;
        }
        $zone = self::zone($tld);
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $zone) !== 1) {
            return null;
        }
        $last = $this->redis->get(self::lastKey($zone));
        $last = is_string($last) && WhoisReplyReader::isValidServer($last) ? $last : null;
        if ($this->redis->exists(self::freshKey($zone))) {
            return $last;
        }
        if ($this->limiter->hit('whois:iana', self::IANA_PER_HOUR, 3600) > 0) {
            return $last;
        }
        try {
            $learned = WhoisReplyReader::ianaReferral($this->whois->query(self::IANA, $zone));
        } catch (WhoisUnavailable) {
            return $last;
        }
        if ($learned === null) {
            // IANA answered but names no server: remember that for a day, keep any last good one.
            $this->redis->setEx(self::freshKey($zone), self::NONE_SECONDS, '1');

            return $last;
        }
        $this->redis->set(self::lastKey($zone), $learned);
        $this->redis->setEx(self::freshKey($zone), self::FRESH_SECONDS, '1');

        return $learned;
    }

    private static function freshKey(string $zone): string
    {
        return 'pc:whois:server:fresh:' . $zone;
    }

    private static function lastKey(string $zone): string
    {
        return 'pc:whois:server:last:' . $zone;
    }
}
