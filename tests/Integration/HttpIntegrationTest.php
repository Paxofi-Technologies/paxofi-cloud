<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\Middleware\SecurityHeadersMiddleware;

/** Boots the real application (PCF kernel, providers, MySQL, Redis) without a web server. */
final class HttpIntegrationTest extends IntegrationTestCase
{
    public function testReadinessIsHealthyAgainstRealMysqlAndRedis(): void
    {
        $response = $this->get('/health/ready');

        self::assertSame(200, $response->status());
        self::assertSame('{"status":"healthy"}', $response->body());
    }

    public function testEveryResponseCarriesSecurityHeadersAndAServerRequestId(): void
    {
        foreach (['/health/live', '/health/ready', '/does-not-exist'] as $path) {
            $response = $this->get($path, ['HTTP_X_REQUEST_ID' => "bad\r\nid"]);
            foreach (array_keys(SecurityHeadersMiddleware::HEADERS) as $header) {
                self::assertNotNull($response->header($header), $path . ' missing ' . $header);
            }
            self::assertSame('nosniff', $response->header('x-content-type-options'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->header('x-request-id'));
        }
    }

    public function testUnknownRouteAndWrongMethodUseProblemDetails(): void
    {
        $notFound = $this->get('/does-not-exist');
        self::assertSame(404, $notFound->status());
        self::assertSame('application/problem+json', $notFound->header('content-type'));
        self::assertStringContainsString('"code":"route_not_found"', $notFound->body());

        $wrongMethod = $this->get('/health/live', ['REQUEST_METHOD' => 'POST']);
        self::assertSame(405, $wrongMethod->status());
        self::assertStringContainsString('"code":"method_not_allowed"', $wrongMethod->body());
    }

    /** @param array<string, string> $server */
    private function get(string $path, array $server = []): HttpResponse
    {
        $request = (new RequestFactory())->fromGlobals($server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path], []);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }
}
