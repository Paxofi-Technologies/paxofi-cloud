<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DR-5: provider numbers arrive as their exact text, never as floats. */
final class ExactJsonTest extends TestCase
{
    public function testNumbersBecomeTheirExactText(): void
    {
        $decoded = ExactJson::decode('{"price": 10.81, "big": 0.1000000000000000055511151231257827, "n": -3, "e": 1.5e3, "list": [1, 2.50]}');

        self::assertSame(['price' => '10.81', 'big' => '0.1000000000000000055511151231257827', 'n' => '-3', 'e' => '1.5e3', 'list' => ['1', '2.50']], $decoded);
    }

    public function testDigitsInsideStringsAndEscapesAreUntouched(): void
    {
        $decoded = ExactJson::decode('{"name": "com 10.5 \"quoted 7\" \\\\", "unicode": "é", "ok": true, "none": null}');

        self::assertSame(['name' => 'com 10.5 "quoted 7" \\', 'unicode' => 'é', 'ok' => true, 'none' => null], $decoded);
    }

    public function testNoFloatSurvivesAnywhere(): void
    {
        $decoded = ExactJson::decode('{"items":[{"prices":[{"register":{"price":12.3456,"period":1}}]}]}');

        array_walk_recursive($decoded, static function (mixed $value): void {
            self::assertIsNotFloat($value);
        });
        self::assertStringContainsString('"price":"12.3456"', json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'not JSON' => ['<html>'];
        yield 'truncated' => ['{"a": 1'];
        yield 'scalar' => ['42'];
        yield 'string' => ['"x"'];
        yield 'leading zero' => ['{"a": 01}'];
        yield 'too deep' => [str_repeat('[', 40) . str_repeat(']', 40)];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingButJsonContainers(string $body): void
    {
        $this->expectException(MalformedProviderResponse::class);

        ExactJson::decode($body);
    }
}
