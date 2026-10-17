<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Security;

use PaxofiCloud\Integration\Security\PwnedPasswordsRangeCheck;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** Threat model T-06: k-anonymity range lookup, padding, fail-open. */
final class PwnedPasswordsRangeCheckTest extends TestCase
{
    // SHA-1("password") = 5BAA6 1E4C9B93F3F0682250B6CF8331B7EE68FD8
    private const string PASSWORD = 'password';
    private const string PREFIX = '5baa6';
    private const string SUFFIX = '1E4C9B93F3F0682250B6CF8331B7EE68FD8';

    public function testOnlyTheFiveCharacterPrefixIsSentAndPaddingIsRequested(): void
    {
        $transport = (new FakeProviderTransport())->expectRaw('GET', '/range/' . self::PREFIX, 200, "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n");
        $check = new PwnedPasswordsRangeCheck($transport, new RecordingLogger());

        self::assertFalse($check->isBreached(self::PASSWORD));
        $sent = $transport->sent[0];
        self::assertSame(PwnedPasswordsRangeCheck::BASE_URL, $sent['baseUrl']);
        self::assertSame('true', $sent['secret'], 'The credential slot carries Add-Padding: true');
        self::assertSame('Add-Padding: true', PwnedPasswordsRangeCheck::endpoint()->authorization('true'));
        $wire = json_encode([$sent['request']->path, $sent['request']->query], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PASSWORD, $wire);
        self::assertStringNotContainsStringIgnoringCase(self::SUFFIX, $wire, 'The hash suffix never leaves our network');
    }

    public function testAMatchingSuffixWithACountIsABreach(): void
    {
        $body = "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n" . strtolower(self::SUFFIX) . ":9545824\r\n";
        $transport = (new FakeProviderTransport())->expectRaw('GET', '/range/' . self::PREFIX, 200, $body);

        self::assertTrue((new PwnedPasswordsRangeCheck($transport, new RecordingLogger()))->isBreached(self::PASSWORD));
    }

    public function testPaddingEntriesWithACountOfZeroAreNotHits(): void
    {
        $transport = (new FakeProviderTransport())->expectRaw('GET', '/range/' . self::PREFIX, 200, self::SUFFIX . ":0\n");

        self::assertFalse((new PwnedPasswordsRangeCheck($transport, new RecordingLogger()))->isBreached(self::PASSWORD));
    }

    public function testOutagesAndErrorsReturnUnknownAndAreLoggedWithoutPasswordData(): void
    {
        $logger = new RecordingLogger();
        $transport = (new FakeProviderTransport())
            ->expectFailure('GET', '/range/' . self::PREFIX)
            ->expectRaw('GET', '/range/' . self::PREFIX, 503, 'unavailable');
        $check = new PwnedPasswordsRangeCheck($transport, $logger);

        self::assertNull($check->isBreached(self::PASSWORD));
        self::assertNull($check->isBreached(self::PASSWORD));
        self::assertSame([
            ['level' => 'warning', 'message' => 'password_breach_check.unavailable', 'context' => ['reason' => 'transport']],
            ['level' => 'warning', 'message' => 'password_breach_check.unavailable', 'context' => ['reason' => 'http_503']],
        ], $logger->records, 'Only a fixed reason is logged: no password, hash or prefix');
    }
}
