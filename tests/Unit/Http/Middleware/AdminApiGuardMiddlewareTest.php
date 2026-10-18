<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http\Middleware;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Request;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Http\Middleware\AdminApiGuardMiddleware;
use PaxofiCloud\Tests\Support\CallableHandler;
use PHPUnit\Framework\TestCase;

/** The admin API exists only on the admin host, and writes only from the admin origin (§6.18). */
final class AdminApiGuardMiddlewareTest extends TestCase
{
    public function testWithoutAdminUrlTheAdminApiIsNotFound(): void
    {
        self::assertSame(404, $this->send(new AdminApiGuardMiddleware(''), 'GET', '/admin-api/v1/auth/session', ['host' => 'admin.example.test']));
    }

    public function testOnAnyOtherHostTheAdminApiIsNotFound(): void
    {
        $guard = new AdminApiGuardMiddleware('https://admin.example.test');
        foreach (['cloud.example.test', 'admin.example.test.evil.example', 'ADMIN.example.test:8443', ''] as $host) {
            self::assertSame(404, $this->send($guard, 'GET', '/admin-api/v1/auth/session', $host === '' ? [] : ['host' => $host]), $host);
        }
    }

    public function testReadsPassOnTheAdminHost(): void
    {
        $guard = new AdminApiGuardMiddleware('https://admin.example.test');
        self::assertSame(204, $this->send($guard, 'GET', '/admin-api/v1/auth/session', ['host' => 'Admin.Example.Test']));
    }

    public function testWritesNeedTheAdminOriginAndJson(): void
    {
        $guard = new AdminApiGuardMiddleware('https://admin.example.test');
        $base = ['host' => 'admin.example.test', 'content-type' => 'application/json'];

        self::assertSame(403, $this->send($guard, 'POST', '/admin-api/v1/auth/passkey', $base));
        self::assertSame(403, $this->send($guard, 'POST', '/admin-api/v1/auth/passkey', $base + ['origin' => 'https://cloud.example.test']));
        self::assertSame(403, $this->send($guard, 'POST', '/admin-api/v1/auth/passkey', $base + ['origin' => 'http://admin.example.test']));
        self::assertSame(415, $this->send($guard, 'POST', '/admin-api/v1/auth/passkey', ['host' => 'admin.example.test', 'origin' => 'https://admin.example.test', 'content-type' => 'text/plain']));
        self::assertSame(204, $this->send($guard, 'POST', '/admin-api/v1/auth/passkey', $base + ['origin' => 'https://admin.example.test']));
    }

    public function testOtherPathsAreUntouched(): void
    {
        $guard = new AdminApiGuardMiddleware('https://admin.example.test');
        self::assertSame(204, $this->send($guard, 'POST', '/api/v1/auth/sign-in', ['host' => 'cloud.example.test']));
        self::assertSame(204, $this->send($guard, 'GET', '/admin-apix', ['host' => 'cloud.example.test']));
    }

    public function testAPortInTheAdminUrlIsPartOfTheAuthority(): void
    {
        $guard = new AdminApiGuardMiddleware('http://localhost:8081');
        self::assertSame(204, $this->send($guard, 'GET', '/admin-api/v1/auth/session', ['host' => 'localhost:8081']));
        self::assertSame(404, $this->send($guard, 'GET', '/admin-api/v1/auth/session', ['host' => 'localhost']));
    }

    /** @param array<string, string> $headers */
    private function send(AdminApiGuardMiddleware $guard, string $method, string $uri, array $headers): int
    {
        return $guard->process(new Request($method, $uri, $headers, '{}'), new CallableHandler(static fn (HttpRequest $r): HttpResponse => new Response(204)))->status();
    }
}
