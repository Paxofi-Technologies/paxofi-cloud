<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiAvailabilityParser;
use PHPUnit\Framework\TestCase;

/** DS-13: the bulk-search answer is read defensively; anything unclear is unknown. */
final class DomainNameApiAvailabilityParserTest extends TestCase
{
    public function testReadsListsOrWrappedListsAndMapsStatusesSafely(): void
    {
        $asked = ['a.com', 'b.com', 'c.com', 'd.com', 'e.com'];
        $answers = DomainNameApiAvailabilityParser::parse(['data' => [
            ['domainName' => 'A.com', 'status' => 'available'],
            ['domainName' => 'b.com', 'status' => 'Not Available'],
            ['domainName' => 'c.com', 'status' => 'Reserved'],
            ['domainName' => 'd.com', 'status' => 'weird-new-status'],
            ['domainName' => 'e.com', 'status' => 'available', 'isPremium' => true, 'price' => '1e9', 'currency' => 'USD'],
            'junk',
        ]], $asked);

        self::assertSame('available', $answers['a.com']->status);
        self::assertSame('taken', $answers['b.com']->status);
        self::assertSame('unavailable', $answers['c.com']->status);
        self::assertSame('unknown', $answers['d.com']->status, 'unrecognised is never available');
        self::assertTrue($answers['e.com']->premium);
        self::assertNull($answers['e.com']->premiumCost, 'a premium price that is not plain decimal text is dropped');
    }

    public function testAPremiumPriceInAnUnknownCurrencyIsDropped(): void
    {
        $answers = DomainNameApiAvailabilityParser::parse([['domainName' => 'a.com', 'status' => 'available', 'isPremium' => true, 'price' => '99.00', 'currency' => 'XYZ']], ['a.com']);
        self::assertTrue($answers['a.com']->premium);
        self::assertNull($answers['a.com']->premiumCost);
        self::assertSame([], DomainNameApiAvailabilityParser::parse(['unexpected' => 'shape'], ['a.com']));
    }
}
