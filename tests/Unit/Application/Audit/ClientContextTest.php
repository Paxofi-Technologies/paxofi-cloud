<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Audit;

use PaxofiCloud\Application\Audit\ClientContext;
use PHPUnit\Framework\TestCase;

/** Threat model T-50: reported values are cleaned and bounded, never trusted. */
final class ClientContextTest extends TestCase
{
    public function testKeepsWellFormedValues(): void
    {
        $client = ClientContext::from('203.0.113.45', 'UA', 'NG', 'Lagos', 'Ikeja', 'req-abcdef12');

        self::assertSame(['203.0.113.45', 'UA', 'NG', 'Lagos', 'Ikeja', 'req-abcdef12'], [$client->ip, $client->userAgent, $client->country, $client->region, $client->city, $client->requestId]);
        self::assertSame('203.0.113.0/24', $client->network());
    }

    public function testIpv6NetworkIsTheSlash64(): void
    {
        self::assertSame('2001:db8:1:2::/64', ClientContext::ip('2001:db8:1:2:3:4:5:6')->network());
    }

    public function testRejectsMalformedOrForgedValues(): void
    {
        $client = ClientContext::from('not-an-ip', "Bot\r\nX-Injected: 1", 'nigeria', "\x01\x02", str_repeat('é', 100), 'bad id!');

        self::assertSame('', $client->ip);
        self::assertSame('', $client->network());
        self::assertSame('Bot X-Injected: 1', $client->userAgent, 'control characters cannot split a log line (T-41)');
        self::assertNull($client->country);
        self::assertNull($client->region);
        self::assertSame(64, mb_strlen((string) $client->city));
        self::assertSame('', $client->requestId);
    }

    public function testUnknownCountryAndInvalidUtf8AreDropped(): void
    {
        $client = ClientContext::from('198.51.100.1', "\xff\xfe", 'XX', "\xc3\x28", null, '');

        self::assertNull($client->country);
        self::assertNull($client->region);
        self::assertSame('', $client->userAgent);
    }

    public function testUserAgentIsCapped(): void
    {
        self::assertSame(512, mb_strlen(ClientContext::from('198.51.100.1', str_repeat('a', 2000), null, null, null, '')->userAgent));
    }
}
