<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http;

use Paxofi\Core\Http\Request;
use PaxofiCloud\Http\SessionCookie;
use PaxofiCloud\Http\StaffSessionCookie;
use PHPUnit\Framework\TestCase;

/** The staff cookie is separate from the customer cookie and SameSite=Strict (D-6, §6.18). */
final class StaffSessionCookieTest extends TestCase
{
    private const string ID = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ0123456';

    public function testIssuedCookieIsHostOnlyStrictAndSessionScoped(): void
    {
        self::assertSame('__Host-pc_staff=' . self::ID . '; Path=/; Secure; HttpOnly; SameSite=Strict', StaffSessionCookie::issue(self::ID));
        self::assertStringContainsString('Max-Age=0', StaffSessionCookie::clear());
    }

    public function testOnlyTheStaffCookieIsRead(): void
    {
        $customer = new Request('GET', '/', ['cookie' => SessionCookie::NAME . '=' . self::ID], '');
        $staff = new Request('GET', '/', ['cookie' => 'a=b; ' . StaffSessionCookie::NAME . '=' . self::ID], '');
        $bad = new Request('GET', '/', ['cookie' => StaffSessionCookie::NAME . '=../../etc'], '');

        self::assertNull(StaffSessionCookie::read($customer));
        self::assertSame(self::ID, StaffSessionCookie::read($staff));
        self::assertNull(StaffSessionCookie::read($bad));
        self::assertNull(SessionCookie::read($staff), 'and the customer side never reads the staff cookie');
    }
}
