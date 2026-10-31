<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Domains\WhoisReplyReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-15, DS-17, DS-T14, DS-T19. Replies are shortened samples of registry formats. */
final class WhoisReplyReaderTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function replies(): iterable
    {
        yield 'verisign no match' => ["No match for \"PAXOFI-NOT-REAL.COM\".\r\n>>> Last update of whois database: 2026-10-31T10:00:00Z <<<\r\n", 'available'];
        yield 'comment-prefixed not found' => ["% This is the registry WHOIS\n%\n% NOT FOUND\n", 'available'];
        yield 'status free' => ["Domain: example.de\nStatus: free\n", 'available'];
        yield 'no object found' => ["No Object Found\n", 'available'];
        yield 'registered record' => ["Domain Name: paxofi.ng\nRegistry Domain ID: 123\nRegistrar: Example\nRegistrant Email: Redacted for Privacy\n", 'taken'];
        yield 'phrase inside a field is not a match' => ["Domain Name: example.ng\nRegistrant Email: not found\n", 'taken'];
        yield 'rate limited' => ["Query limit exceeded, please try again later.\n", 'unknown'];
        yield 'rate limit wins over no match' => ["No match for X\nToo many queries from your IP\n", 'unknown'];
        yield 'empty' => ["  \r\n", 'unknown'];
        yield 'unknown text is taken, never available' => ["Something unusual happened\n", 'taken'];
    }

    #[DataProvider('replies')]
    public function testReadsReplies(string $reply, string $expected): void
    {
        self::assertSame($expected, WhoisReplyReader::read($reply));
    }

    public function testAStaffPhraseIsUsedOnlyWhenValid(): void
    {
        $reply = "Registry says: the name is free\n";
        self::assertSame('taken', WhoisReplyReader::read($reply));
        self::assertSame('available', WhoisReplyReader::read($reply, 'Registry says: the name is free'));
        self::assertSame('taken', WhoisReplyReader::read("a\n", 'a'), 'DS-T19: too short a phrase is ignored');
        self::assertFalse(WhoisReplyReader::isValidPhrase("free\u{0007}"));
        self::assertFalse(WhoisReplyReader::isValidPhrase(str_repeat('x', 101)));
    }

    public function testReadsTheIanaReferral(): void
    {
        $iana = "% IANA WHOIS server\n\ndomain:       NG\n\norganisation: NiRA\n\nwhois:        whois.nic.net.ng\n\nstatus:       ACTIVE\n";
        self::assertSame('whois.nic.net.ng', WhoisReplyReader::ianaReferral($iana));
        self::assertSame('whois.verisign-grs.com', WhoisReplyReader::ianaReferral("refer:        whois.verisign-grs.com\nwhois: whois.other.example\n"), 'refer wins');
        self::assertNull(WhoisReplyReader::ianaReferral("refer: 10.0.0.1\n"), 'DS-T13: an IP is never accepted');
        self::assertNull(WhoisReplyReader::ianaReferral("refer: evil.example:4444\n"));
        self::assertNull(WhoisReplyReader::ianaReferral("domain: XX\n"));
    }
}
