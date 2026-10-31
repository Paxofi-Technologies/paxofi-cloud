<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/**
 * Reads a WHOIS reply as available, taken or unknown (DS-17, DS-T14). A name
 * is available only when a line of the reply starts with a known "no match"
 * phrase (after any `%`, `#` or `>>>` comment marker); a phrase that only
 * appears inside a field such as "Registrant Email: not found" does not
 * count. A rate-limit or refusal phrase anywhere, or an empty reply, is
 * unknown. Every other reply is taken: a wrong guess errs towards taken.
 */
final class WhoisReplyReader
{
    /** Line-start phrases, lower case, from registries' published replies. */
    public const array NO_MATCH = [
        'no match for',
        'no match',
        'not found',
        'no data found',
        'no entries found',
        'no object found',
        'domain not found',
        'status: free',
        'status: available',
        'object does not exist',
        'the queried object does not exist',
        'this domain name has not been registered',
        'no information available',
        'is available for registration',
    ];

    /** Anywhere in the reply, lower case. */
    public const array REFUSED = [
        'limit exceeded',
        'quota exceeded',
        'try again later',
        'too many requests',
        'too many queries',
        'access denied',
        'query rate',
        'temporarily unavailable',
        'blacklisted',
    ];

    public const int MIN_PHRASE = 3;
    public const int MAX_PHRASE = 100;

    /**
     * @param ?string $extraPhrase a staff phrase for this extension (DS-17): plain text, case-insensitive, line start
     * @return 'available'|'taken'|'unknown'
     */
    public static function read(string $reply, ?string $extraPhrase = null): string
    {
        $text = strtolower($reply);
        if (trim($text) === '') {
            return 'unknown';
        }
        foreach (self::REFUSED as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'unknown';
            }
        }
        $phrases = self::NO_MATCH;
        if ($extraPhrase !== null && self::isValidPhrase($extraPhrase)) {
            $phrases[] = strtolower(trim($extraPhrase));
        }
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = ltrim($line, " \t%#>");
            foreach ($phrases as $phrase) {
                if (str_starts_with($line, $phrase)) {
                    return 'available';
                }
            }
        }

        return 'taken';
    }

    /** A WHOIS server must be a DNS host name: no IP literal, port, user or path (DS-15). */
    public static function isValidServer(string $server): bool
    {
        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $server) === 1
            && filter_var($server, FILTER_VALIDATE_IP) === false;
    }

    public static function isValidPhrase(string $phrase): bool
    {
        $phrase = trim($phrase);

        return strlen($phrase) >= self::MIN_PHRASE && strlen($phrase) <= self::MAX_PHRASE && preg_match('/^[\x20-\x7e]+$/', $phrase) === 1;
    }

    /**
     * The WHOIS server named in an IANA reply for an extension (DS-15): the
     * `refer:` line, else the `whois:` line. Null when neither names a valid host.
     */
    public static function ianaReferral(string $reply): ?string
    {
        $found = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            if (preg_match('/^\s*(refer|whois):\s*(\S+)\s*$/i', $line, $m) === 1) {
                $found[strtolower($m[1])] ??= strtolower($m[2]);
            }
        }
        foreach (['refer', 'whois'] as $key) {
            $host = $found[$key] ?? null;
            if ($host !== null && self::isValidServer($host)) {
                return $host;
            }
        }

        return null;
    }
}
