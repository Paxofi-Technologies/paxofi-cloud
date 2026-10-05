<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http;

use PaxofiCloud\Http\RequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestFactoryTest extends TestCase
{
    public function testBuildsRequestFromServerGlobals(): void
    {
        $request = (new RequestFactory())->fromGlobals([
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/v1/carts?x=1',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'abcdef12-3456',
        ], ['x' => '1'], $this->stream('{"a":1}'));

        self::assertSame('POST', $request->method());
        self::assertSame('/v1/carts?x=1', $request->uri());
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('abcdef12-3456', $request->header('x-request-id'));
        self::assertSame('{"a":1}', $request->body());
        self::assertSame(['x' => '1'], $request->query());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequestIds(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'newline injection' => ["abcdefgh\r\nSet-Cookie: x=1"];
        yield 'spaces' => ['abcdefgh ijk'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'script' => ['<script>alert(1)</script>'];
    }

    #[DataProvider('invalidRequestIds')]
    public function testDropsInvalidClientRequestIdSoPcfGeneratesOne(string $id): void
    {
        $request = (new RequestFactory())->fromGlobals(['HTTP_X_REQUEST_ID' => $id], []);

        self::assertNull($request->header('x-request-id'));
    }

    public function testFlagsOversizedBodyWithoutBufferingIt(): void
    {
        $request = (new RequestFactory(maxBodyBytes: 10))->fromGlobals(['REQUEST_METHOD' => 'POST'], [], $this->stream(str_repeat('x', 11)));

        self::assertSame('', $request->body());
        self::assertTrue($request->attributes()[RequestFactory::BODY_TOO_LARGE] ?? false);
    }

    public function testAcceptsBodyAtExactlyTheLimit(): void
    {
        $request = (new RequestFactory(maxBodyBytes: 10))->fromGlobals(['REQUEST_METHOD' => 'POST'], [], $this->stream(str_repeat('x', 10)));

        self::assertSame(str_repeat('x', 10), $request->body());
        self::assertArrayNotHasKey(RequestFactory::BODY_TOO_LARGE, $request->attributes());
    }

    public function testDropsNestedQueryArrays(): void
    {
        $request = (new RequestFactory())->fromGlobals([], [
            'q' => 'okafor',
            'tld' => ['ng', 'com.ng'],
            'filter' => ['a' => ['b' => 'c']],
            0 => 'numeric-key',
        ]);

        self::assertSame(['q' => 'okafor', 'tld' => ['ng', 'com.ng']], $request->query());
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
