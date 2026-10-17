<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http\Middleware;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Request;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Http\Middleware\ApiWriteGuardMiddleware;
use PaxofiCloud\Tests\Support\CallableHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Threat model T-21, T-48 (OriginGuardTest). */
final class ApiWriteGuardMiddlewareTest extends TestCase
{
    public function testSameOriginJsonWriteReachesTheController(): void
    {
        self::assertSame(204, $this->send('POST', '/api/v1/auth/register', 'https://cloud-staging.paxofi.com', 'application/json; charset=utf-8')->status());
    }

    /** @return iterable<string, array{?string}> */
    public static function badOrigins(): iterable
    {
        yield 'missing' => [null];
        yield 'other site' => ['https://evil.example'];
        yield 'look-alike' => ['https://cloud-staging.paxofi.com.evil.example'];
        yield 'http downgrade' => ['http://cloud-staging.paxofi.com'];
        yield 'other port' => ['https://cloud-staging.paxofi.com:8443'];
        yield 'opaque origin' => ['null'];
    }

    #[DataProvider('badOrigins')]
    public function testWritesFromAnyOtherOriginAreRefused(?string $origin): void
    {
        $response = $this->send('POST', '/api/v1/auth/register', $origin, 'application/json');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('"code":"origin_not_allowed"', $response->body());
    }

    /** @return iterable<string, array{?string}> */
    public static function nonJson(): iterable
    {
        yield 'form post' => ['application/x-www-form-urlencoded'];
        yield 'multipart' => ['multipart/form-data; boundary=x'];
        yield 'text/plain (CORS-simple)' => ['text/plain'];
        yield 'missing' => [null];
    }

    #[DataProvider('nonJson')]
    public function testCorsSimpleContentTypesAreRefused(?string $type): void
    {
        self::assertSame(415, $this->send('POST', '/api/v1/auth/register', 'https://cloud-staging.paxofi.com', $type)->status());
    }

    public function testSafeMethodsAndNonApiPathsAreUntouched(): void
    {
        self::assertSame(204, $this->send('GET', '/api/v1/anything', null, null)->status());
        self::assertSame(204, $this->send('POST', '/health/live', null, null)->status());
    }

    public function testOriginComparisonIgnoresCase(): void
    {
        self::assertSame(204, $this->send('PUT', '/api/v1/x', 'HTTPS://Cloud-Staging.Paxofi.com', 'application/json')->status());
    }

    private function send(string $method, string $uri, ?string $origin, ?string $contentType): HttpResponse
    {
        $headers = array_filter(['origin' => $origin, 'content-type' => $contentType], static fn (?string $v): bool => $v !== null);
        $guard = new ApiWriteGuardMiddleware('https://cloud-staging.paxofi.com');

        return $guard->process(new Request($method, $uri, $headers, '{}'), new CallableHandler(static fn (HttpRequest $r): HttpResponse => new Response(204)));
    }
}
