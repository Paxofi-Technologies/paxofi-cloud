<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Payments;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\DecimalAmount;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\GatewayKeyGuard;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PAYMENTS.md PY-4, PY-5 / PT-09; PRC-001 (no floats). */
final class PaymentsDomainTest extends TestCase
{
    public function testReferencesArePxcUlidsThatSortByTimeAndNeverRepeat(): void
    {
        $a = PaymentReference::generate(new \DateTimeImmutable('2026-10-26T12:00:00.000Z'));
        $b = PaymentReference::generate(new \DateTimeImmutable('2026-10-26T12:00:00.001Z'));
        $c = PaymentReference::generate(new \DateTimeImmutable('2026-10-26T12:00:00.001Z'));

        self::assertMatchesRegularExpression(PaymentReference::PATTERN, $a->value);
        self::assertSame(30, strlen($a->value));
        self::assertLessThan(0, strcmp(substr($a->value, 0, 14), substr($b->value, 0, 14)), 'later time sorts later');
        self::assertSame(substr($b->value, 0, 14), substr($c->value, 0, 14));
        self::assertNotSame($b->value, $c->value, '80 random bits per reference');
        self::assertSame('PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6W', PaymentReference::fromString('PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6W')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignReferences(): iterable
    {
        yield 'invoice number' => ['PXC-2026-000001'];
        yield 'lower case' => ['pxc-01jb8z5x3r2q4m6n8p0s2t4v6w'];
        yield 'excluded letter I' => ['PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6I'];
        yield 'time overflow' => ['PXC-81JB8Z5X3R2Q4M6N8P0S2T4V6W'];
        yield 'too long' => ['PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6WX'];
        yield 'injection' => ["PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6W\n"];
    }

    #[DataProvider('foreignReferences')]
    public function testOnlyOurOwnReferenceShapeIsAccepted(string $value): void
    {
        self::assertNull(PaymentReference::tryFrom($value));
        $this->expectException(\InvalidArgumentException::class);
        PaymentReference::fromString($value);
    }

    public function testKeyModesComeFromDocumentedPrefixes(): void
    {
        $cases = [
            ['paystack', self::key('sk_test_'), GatewayMode::Test],
            ['paystack', self::key('sk_live_'), GatewayMode::Live],
            ['paystack', self::key('pk_test_'), null],
            ['paystack', self::key('FLWSECK_TEST-', '-X'), null],
            ['flutterwave', self::key('FLWSECK_TEST-', '-X'), GatewayMode::Test],
            ['flutterwave', self::key('FLWSECK-', '-X'), GatewayMode::Live],
            ['flutterwave', self::key('FLWPUBK_TEST-', '-X'), null],
            ['flutterwave', self::key('sk_test_'), null],
            ['paystack', 'sk_test_short', null],
            ['paystack', self::key('sk_live_') . "\n", null],
        ];
        foreach ($cases as [$gateway, $key, $mode]) {
            self::assertSame($mode, GatewayKeyGuard::modeOf(Gateway::from($gateway), $key), $gateway . ' ' . substr($key, 0, 14));
        }
        self::assertFalse(GatewayKeyGuard::allows(Gateway::Paystack, self::key('sk_live_'), GatewayMode::Test));
        self::assertTrue(GatewayKeyGuard::allows(Gateway::Paystack, self::key('sk_test_'), GatewayMode::Test));
    }

    /** Fake keys are assembled at run time so no key-shaped literal sits in the repository (push protection). */
    private static function key(string $prefix, string $suffix = ''): string
    {
        return $prefix . str_repeat('0a1b2c3d', 5) . $suffix;
    }

    public function testOnlyProductionTakesLiveKeys(): void
    {
        self::assertSame(GatewayMode::Live, GatewayMode::forEnvironment('production'));
        foreach (['staging', 'local', 'testing', '', 'Production'] as $env) {
            self::assertSame(GatewayMode::Test, GatewayMode::forEnvironment($env), $env);
        }
    }

    public function testPaystackMinimums(): void
    {
        self::assertSame(5_000, Gateway::Paystack->minimum(Currency::NGN)?->minorUnits);
        self::assertSame(200, Gateway::Paystack->minimum(Currency::USD)?->minorUnits);
        self::assertNull(Gateway::Flutterwave->minimum(Currency::USD));
    }

    public function testDecimalAmountsConvertExactly(): void
    {
        foreach (['17737.50' => 1_773_750, '17737.5' => 1_773_750, '100' => 10_000, '0.07' => 7, '1.500' => 150, '99999999999.99' => 9_999_999_999_999] as $text => $minor) {
            self::assertSame($minor, DecimalAmount::toMinor((string) $text, Currency::NGN)->minorUnits, (string) $text);
        }
        self::assertSame('17737.50', DecimalAmount::toText(new Money(1_773_750, Currency::NGN)));
        self::assertSame('0.07', DecimalAmount::toText(new Money(7, Currency::USD)));
        self::assertSame('2.00', DecimalAmount::toText(new Money(200, Currency::USD)));
    }

    public function testUnreadableDecimalsAreRefusedNotRounded(): void
    {
        foreach (['1.505', '-1.00', '1e3', '01.00', '1.', '.5', ' 1', '1,000.00', '', '100000000000'] as $text) {
            try {
                DecimalAmount::toMinor($text, Currency::NGN);
                self::fail('accepted ' . $text);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
