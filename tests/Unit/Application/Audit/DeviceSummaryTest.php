<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Audit;

use PaxofiCloud\Application\Audit\DeviceSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceSummaryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function agents(): iterable
    {
        yield 'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome 129 on Windows (desktop)'];
        yield 'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.79', 'Edge 129 on Windows (desktop)'];
        yield 'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'Safari 17 on macOS 10.15 (desktop)'];
        yield 'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Safari 17 on iOS 17.6 (mobile)'];
        yield 'Chrome on iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', 'Chrome 129 on iPadOS 17.6 (tablet)'];
        yield 'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', 'Chrome 129 on Android 14 (mobile)'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet 26 on Android 14 (mobile)'];
        yield 'Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome 129 on Android 13 (tablet)'];
        yield 'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox 131 on Linux (desktop)'];
        yield 'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera 114 on Windows (desktop)'];
        yield 'curl' => ['curl/8.5.0', 'Automated client on Unknown (bot)'];
        yield 'empty' => ['', 'Unknown on Unknown (unknown)'];
    }

    #[DataProvider('agents')]
    public function testCommonAgents(string $userAgent, string $label): void
    {
        self::assertSame($label, DeviceSummary::fromUserAgent($userAgent)->label());
    }

    public function testHostileInputIsBoundedAndNeverEchoedIntoFields(): void
    {
        $device = DeviceSummary::fromUserAgent(str_repeat('Chrome/', 5000) . "<script>\0");

        self::assertSame('Unknown', $device->os);
        self::assertLessThanOrEqual(16, strlen($device->browserVersion));
    }
}
