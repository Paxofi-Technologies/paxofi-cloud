<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http;

use Paxofi\Core\Http\Request;
use PaxofiCloud\Http\SessionCookie;
use PHPUnit\Framework\TestCase;

/** Threat model D-6, T-18, T-19. */
final class SessionCookieTest extends TestCase
{
    private const string ID = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ0123456';

    public function testIssuedCookieHasEveryProtectiveAttributeAndNoLifetime(): void
    {
        $cookie = SessionCookie::issue(self::ID);

        self::assertSame('__Host-pc_sid=' . self::ID . '; Path=/; Secure; HttpOnly; SameSite=Lax', $cookie);
        self::assertStringNotContainsStringIgnoringCase('domain=', $cookie);
        self::assertStringNotContainsStringIgnoringCase('max-age', $cookie);
    }

    public function testClearingCookieExpiresIt(): void
    {
        self::assertStringContainsString('Max-Age=0', SessionCookie::clear());
        self::assertStringStartsWith('__Host-pc_sid=;', SessionCookie::clear());
    }

    public function testReadsOnlyAWellFormedHostPrefixedCookie(): void
    {
        self::assertSame(self::ID, SessionCookie::read(self::request('theme=dark; __Host-pc_sid=' . self::ID)));
        self::assertNull(SessionCookie::read(self::request('pc_sid=' . self::ID)));
        self::assertNull(SessionCookie::read(self::request('__Host-pc_sid=short')));
        self::assertNull(SessionCookie::read(self::request('__Host-pc_sid=' . self::ID . '%0d%0a')));
        self::assertNull(SessionCookie::read(new Request('GET', '/', [], '')));
    }

    public function testRefusesToIssueAnUnexpectedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionCookie::issue("x\r\nSet-Cookie: evil=1");
    }

    private static function request(string $cookie): Request
    {
        return new Request('GET', '/', ['cookie' => $cookie], '');
    }
}
