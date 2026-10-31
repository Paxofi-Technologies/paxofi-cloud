<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

/**
 * How availability is answered for one extension (DS-14, DS-15, DS-17).
 * `auto`: DNS, registrar, RDAP, then WHOIS. `rdap`, `whois` or `registrar`:
 * DNS (which can only say taken) and then that source only. A fixed WHOIS
 * server replaces the one learned from IANA; a phrase is one more
 * plain-text "no match" line start.
 */
final readonly class LookupSettings
{
    public const array MODES = ['auto', 'rdap', 'whois', 'registrar'];

    public function __construct(
        public string $tld,
        public string $mode = 'auto',
        public ?string $whoisServer = null,
        public ?string $whoisPhrase = null,
    ) {
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$/', $tld) !== 1) {
            throw new \InvalidArgumentException('Invalid extension.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown lookup mode.');
        }
        if ($whoisServer !== null && !WhoisReplyReader::isValidServer($whoisServer)) {
            throw new \InvalidArgumentException('A WHOIS server must be a host name.');
        }
        if ($whoisPhrase !== null && !WhoisReplyReader::isValidPhrase($whoisPhrase)) {
            throw new \InvalidArgumentException('Invalid phrase.');
        }
    }

    public function uses(string $source): bool
    {
        return $this->mode === 'auto' || $this->mode === $source;
    }
}
