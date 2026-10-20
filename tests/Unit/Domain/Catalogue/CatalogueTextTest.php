<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Catalogue;

use PaxofiCloud\Domain\Catalogue\CatalogueRuleViolation;
use PaxofiCloud\Domain\Catalogue\CatalogueText;
use PHPUnit\Framework\TestCase;

/** Commerce threat model CD-11, C-11. */
final class CatalogueTextTest extends TestCase
{
    public function testPlainTextIsAcceptedAndTrimmed(): void
    {
        self::assertSame('VPS Small — 2 vCPU', CatalogueText::name('  VPS Small — 2 vCPU ', 'name'));
        self::assertSame('', CatalogueText::description('', 'description'));
        self::assertSame('cloud-vps', CatalogueText::slug('cloud-vps', 'slug'));
    }

    public function testMarkupControlCharactersAndLengthAreRefused(): void
    {
        foreach ([
            fn () => CatalogueText::name('<script>alert(1)</script>', 'name'),
            fn () => CatalogueText::name("Bold\u{202E}", 'name'),
            fn () => CatalogueText::name("Line\nbreak", 'name'),
            fn () => CatalogueText::name('', 'name'),
            fn () => CatalogueText::name(str_repeat('a', 121), 'name'),
            fn () => CatalogueText::description(str_repeat('a', 1001), 'description'),
            fn () => CatalogueText::slug('Cloud VPS', 'slug'),
            fn () => CatalogueText::slug('../etc', 'slug'),
        ] as $check) {
            try {
                $check();
                self::fail('Accepted unsafe catalogue text');
            } catch (CatalogueRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }
}
