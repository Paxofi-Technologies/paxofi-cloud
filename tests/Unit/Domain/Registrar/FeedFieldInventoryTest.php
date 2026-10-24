<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Registrar;

use PaxofiCloud\Domain\Registrar\FeedField;
use PaxofiCloud\Domain\Registrar\FeedFieldInventory;
use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PHPUnit\Framework\TestCase;

/** DR-16: which fields the registrar sends, by name, never by text value. */
final class FeedFieldInventoryTest extends TestCase
{
    /** @return array<string, FeedField> */
    private static function byPath(FeedFieldInventory $inventory): array
    {
        $fields = [];
        foreach ($inventory->fields() as $field) {
            $fields[$field->path] = $field;
        }

        return $fields;
    }

    public function testRecordsPathsCountsKindsAndWholeNumberRanges(): void
    {
        $inventory = new FeedFieldInventory();
        $inventory->add(ExactJson::decode('{"name":"com","status":"Active","gracePeriod":30,"redemptionPeriod":"30","note":"secret text","prices":[{"register":{"price":10.81,"currency":"USD","period":1},"restore":[{"price":99,"currency":"USD","period":1}]}]}'));
        $inventory->add(ExactJson::decode('{"name":"ng","status":"Active","gracePeriod":40,"redemptionPeriod":null,"prices":[{"register":{"price":"7.5","currency":"USD","period":2}}]}'));

        $fields = self::byPath($inventory);

        self::assertSame(['gracePeriod', 'name', 'note', 'prices', 'prices[].register', 'prices[].register.currency', 'prices[].register.period', 'prices[].register.price', 'prices[].restore', 'prices[].restore.currency', 'prices[].restore.period', 'prices[].restore.price', 'redemptionPeriod', 'status'], array_keys($fields));
        self::assertSame([2, ['text'], 30, 40], [$fields['gracePeriod']->items, $fields['gracePeriod']->kinds, $fields['gracePeriod']->min, $fields['gracePeriod']->max], 'a day count across items');
        self::assertSame([2, ['null', 'text'], null, null], [$fields['redemptionPeriod']->items, $fields['redemptionPeriod']->kinds, $fields['redemptionPeriod']->min, $fields['redemptionPeriod']->max], 'a null breaks the range');
        self::assertSame([1, 2], [$fields['prices[].register.period']->min, $fields['prices[].register.period']->max]);
        self::assertNull($fields['prices[].register.price']->min, 'decimals are not whole numbers');
        self::assertSame(['list'], $fields['prices']->kinds);
        self::assertSame(['list'], $fields['prices[].restore']->kinds);
        self::assertSame(['object'], $fields['prices[].register']->kinds);
        self::assertSame(1, $fields['note']->items);
        self::assertNull($fields['note']->min);
        self::assertStringNotContainsString('secret', serialize($inventory->fields()), 'text values are never kept');
    }

    public function testAKeyRepeatedInsideOneItemCountsThatItemOnce(): void
    {
        $inventory = new FeedFieldInventory();
        $inventory->add(ExactJson::decode('{"prices":[{"register":[{"period":1},{"period":2},{"period":3}]}]}'));

        self::assertSame(1, self::byPath($inventory)['prices[].register.period']->items);
    }

    public function testHostileKeysAreGroupedAsOtherAndPathsAreCapped(): void
    {
        $inventory = new FeedFieldInventory();
        $inventory->add(['<script>' => '1', 'a b' => '2', '0' => 'x', 'ok' => '3']);
        $wide = [];
        for ($i = 0; $i < 400; $i++) {
            $wide['k' . $i] = 'x';
        }
        $inventory->add($wide);

        $fields = self::byPath($inventory);
        self::assertSame(1, $fields['(other)']->items);
        self::assertSame(['text'], $fields['(other)']->kinds);
        self::assertCount(FeedFieldInventory::MAX_PATHS, $fields);
        foreach (array_keys($fields) as $path) {
            self::assertMatchesRegularExpression('/^[A-Za-z(][A-Za-z0-9_.\[\]()]{0,159}$/', $path);
        }
    }

    public function testIgnoresItemsThatAreNotObjects(): void
    {
        $inventory = new FeedFieldInventory();
        $inventory->add([1, 2]);
        $inventory->add('text');
        $inventory->add(null);

        self::assertSame([], $inventory->fields());
    }

    public function testNumbersBeyondFiveDigitsOrNegativeAreNotARange(): void
    {
        $inventory = new FeedFieldInventory();
        $inventory->add(ExactJson::decode('{"big":123456,"neg":-3}'));

        $fields = self::byPath($inventory);
        self::assertNull($fields['big']->min);
        self::assertNull($fields['neg']->min);
    }

    public function testAFieldRefusesOutOfRangeSummaries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeedField('gracePeriod', 1, ['text'], 5, 100000);
    }
}
