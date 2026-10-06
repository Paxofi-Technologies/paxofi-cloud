<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SEC-012 / threat model V-12: a request path can never redirect a call elsewhere. */
final class ProviderRequestTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'absolute URL' => ['https://evil.example/servers'];
        yield 'scheme-relative' => ['//evil.example/servers'];
        yield 'traversal' => ['/servers/../ssh_keys'];
        yield 'query smuggling' => ['/servers?label_selector=x'];
        yield 'fragment' => ['/servers#x'];
        yield 'encoded slash' => ['/servers/1%2F..'];
        yield 'relative' => ['servers'];
        yield 'empty' => [''];
        yield 'uppercase or spaces' => ['/Servers/1 2'];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderRequest::get($path);
    }

    public function testAcceptsPlainPaths(): void
    {
        self::assertSame('/servers/42/actions/poweron', ProviderRequest::post('/servers/42/actions/poweron', [])->path);
        self::assertSame('/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60', ProviderRequest::delete('/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60')->path);
    }

    public function testRejectsBodiesOnGetAndDelete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderRequest('DELETE', '/servers/1', [], ['force' => true]);
    }

    public function testResponseJsonMustBeAnObject(): void
    {
        $this->expectException(MalformedProviderResponse::class);

        (new ProviderResponse(200, [], '[1,2,3]'))->json();
    }

    public function testResponseRejectsInvalidJson(): void
    {
        $this->expectException(MalformedProviderResponse::class);

        (new ProviderResponse(200, [], '<html>gateway</html>'))->json();
    }

    public function testResponseHeadersAreCaseInsensitive(): void
    {
        self::assertSame('1700000000', (new ProviderResponse(429, ['RateLimit-Reset' => '1700000000'], ''))->header('ratelimit-reset'));
    }
}
