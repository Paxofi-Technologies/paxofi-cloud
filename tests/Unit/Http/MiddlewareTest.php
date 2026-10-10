<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\HttpException;
use Paxofi\Core\Http\Request;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Http\Middleware\BodySizeLimitMiddleware;
use PaxofiCloud\Http\Middleware\ErrorBoundaryMiddleware;
use PaxofiCloud\Http\Middleware\RouteFallbackMiddleware;
use PaxofiCloud\Http\Middleware\SecurityHeadersMiddleware;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Tests\Support\CallableHandler;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MiddlewareTest extends TestCase
{
    public function testSecurityHeadersAreAddedAndResponsesAreNotCacheable(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->request(), $this->returning(new Response(200)));

        foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) {
            self::assertSame($value, $response->header($name));
        }
        self::assertSame('no-store', $response->header('cache-control'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->header('content-security-policy'));
    }

    public function testExplicitCacheControlIsKept(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(
            $this->request(),
            $this->returning(new Response(200, ['cache-control' => 'public, max-age=60'])),
        );

        self::assertSame('public, max-age=60', $response->header('cache-control'));
    }

    public function testServerErrorsAreGenericForClientsButFullyLogged(): void
    {
        $logger = new RecordingLogger();
        $response = (new ErrorBoundaryMiddleware($logger))->process(
            $this->request(),
            $this->throwing(new RuntimeException('SQLSTATE[HY000] secret-db-host.internal refused')),
        );

        self::assertSame(500, $response->status());
        self::assertSame('application/problem+json', $response->header('content-type'));
        $body = $this->json($response);
        self::assertSame('internal_error', $body['code']);
        self::assertSame('req-12345678', $body['request_id']);
        self::assertArrayNotHasKey('detail', $body);
        self::assertStringNotContainsString('secret-db-host', $response->body());

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('req-12345678', $logger->records[0]['context']['request_id']);
        self::assertStringContainsString('secret-db-host', (string) json_encode($logger->records[0]['context']));
    }

    public function testDebugModeRevealsServerErrorMessage(): void
    {
        $response = (new ErrorBoundaryMiddleware(new RecordingLogger(), debug: true))->process(
            $this->request(),
            $this->throwing(new RuntimeException('boom')),
        );

        self::assertSame('boom', $this->json($response)['detail']);
    }

    public function testClientErrorsKeepTheirStatusAndMessage(): void
    {
        $response = (new ErrorBoundaryMiddleware(new RecordingLogger()))->process(
            $this->request(),
            $this->throwing(new HttpException(422, 'Domain name is invalid.')),
        );

        self::assertSame(422, $response->status());
        $body = $this->json($response);
        self::assertSame('http_422', $body['code']);
        self::assertSame('Domain name is invalid.', $body['detail']);
    }

    public function testOversizedBodyIsRejectedBeforeReachingTheHandler(): void
    {
        $request = $this->request()->withAttribute(RequestFactory::BODY_TOO_LARGE, true);
        $response = (new BodySizeLimitMiddleware())->process($request, $this->throwing(new RuntimeException('must not run')));

        self::assertSame(413, $response->status());
        self::assertSame('body_too_large', $this->json($response)['code']);
    }

    public function testRouterPlainTextErrorsBecomeProblemDetails(): void
    {
        $middleware = new RouteFallbackMiddleware();

        $notFound = $middleware->process($this->request(), $this->returning(new Response(404, [], 'Not Found')));
        self::assertSame('route_not_found', $this->json($notFound)['code']);

        $wrongMethod = $middleware->process($this->request(), $this->returning(new Response(405, ['allow' => 'GET'], 'Method Not Allowed')));
        self::assertSame(405, $wrongMethod->status());
        self::assertSame('GET', $wrongMethod->header('allow'));
        self::assertSame('method_not_allowed', $this->json($wrongMethod)['code']);

        $handled = new Response(404, ['content-type' => 'application/problem+json'], '{"code":"invoice_not_found"}');
        self::assertSame($handled, $middleware->process($this->request(), $this->returning($handled)));
    }

    private function request(): HttpRequest
    {
        return (new Request('GET', '/x'))->withAttribute('_request_id', 'req-12345678');
    }

    private function returning(HttpResponse $response): CallableHandler
    {
        return new CallableHandler(static fn (HttpRequest $r): HttpResponse => $response);
    }

    private function throwing(\Throwable $exception): CallableHandler
    {
        return new CallableHandler(static function (HttpRequest $r) use ($exception): HttpResponse {
            throw $exception;
        });
    }

    /** @return array<string, mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
