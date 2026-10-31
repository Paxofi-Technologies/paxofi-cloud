<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Domains\NameIdeas;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-23, DS-T21, DS-T22. */
final class NameIdeasTest extends TestCase
{
    private const array ON_SALE = ['com', 'ng', 'com.ng', 'net', 'org', 'online', 'xyz'];

    public function testMixesOtherExtensionsWithPrefixesAndSuffixesOnSaleOnly(): void
    {
        $ideas = NameIdeas::generate('paxofi', 'ng', self::ON_SALE, ['paxofi.ng', 'paxofi.com.ng'], 12);

        self::assertCount(12, $ideas);
        self::assertSame(['paxofi.com', 'paxofi.net', 'paxofi.org', 'paxofi.online', 'paxofi.xyz', 'getpaxofi.com'], array_slice($ideas, 0, 6));
        self::assertContains('paxofihq.ng', $ideas);
        self::assertNotContains('paxofi.ng', $ideas, 'already shown');
        foreach ($ideas as $idea) {
            self::assertContains(explode('.', $idea, 2)[1], self::ON_SALE);
        }
        self::assertSame($ideas, array_values(array_unique($ideas)));
    }

    public function testEveryIdeaIsAValidLabelAndLongLabelsAreNotExtended(): void
    {
        $long = str_repeat('a', 62);
        foreach (NameIdeas::generate($long, 'com', self::ON_SALE, [], 30) as $idea) {
            self::assertLessThanOrEqual(63, strlen(explode('.', $idea, 2)[0]));
            self::assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\./', $idea);
        }
        self::assertSame([], NameIdeas::generate('-bad', 'com', self::ON_SALE, [], 10));
        self::assertSame([], NameIdeas::generate('paxofi', 'com', self::ON_SALE, [], 0));
    }

    public function testStaffWordsAreCleaned(): void
    {
        self::assertSame(['get', 'shop'], NameIdeas::clean(['Get', 'shop', 'x', 'bad word', 'ẹkọ', str_repeat('a', 13), 'get']));
        $ideas = NameIdeas::generate('paxofi', 'com', ['com'], [], 10, ['go', '<script>'], []);
        self::assertSame(['paxofi.com', 'gopaxofi.com'], $ideas);
    }

    public function testASuffixTheLabelAlreadyEndsWithIsSkipped(): void
    {
        self::assertNotContains('foodhubhub.com', NameIdeas::generate('foodhub', 'com', ['com'], [], 30));
    }
}
