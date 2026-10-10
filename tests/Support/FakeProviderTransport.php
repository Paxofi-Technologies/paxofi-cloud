<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PHPUnit\Framework\Assert;

/**
 * Scripted in-memory provider API. Each expectation names the exact method
 * and path (and optionally query) it answers; any unexpected call fails the
 * test, so tests also prove which calls an adapter does *not* make.
 */
final class FakeProviderTransport implements ProviderTransport
{
    /** @var list<array{string, string, array<string, string|int>|null, ProviderResponse|TransportFailure}> */
    private array $script = [];

    /** @var list<array{baseUrl: string, request: ProviderRequest, credentialId: string, secret: string}> */
    public array $sent = [];

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string|int>|null $query
     * @param array<string, string> $headers
     */
    public function expect(string $method, string $path, int $status, ?array $json = null, ?array $query = null, array $headers = []): self
    {
        $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR);
        $this->script[] = [$method, $path, $query, new ProviderResponse($status, $headers + ['Content-Type' => 'application/json'], $body)];

        return $this;
    }

    public function expectRaw(string $method, string $path, int $status, string $body): self
    {
        $this->script[] = [$method, $path, null, new ProviderResponse($status, [], $body)];

        return $this;
    }

    public function expectFailure(string $method, string $path): self
    {
        $this->script[] = [$method, $path, null, new TransportFailure('connection timed out')];

        return $this;
    }

    public function send(string $baseUrl, ProviderRequest $request, ProviderCredential $credential): ProviderResponse
    {
        $this->sent[] = ['baseUrl' => $baseUrl, 'request' => $request, 'credentialId' => $credential->credentialId, 'secret' => $credential->reveal()];

        $next = array_shift($this->script);
        Assert::assertNotNull($next, sprintf('Unexpected provider call %s %s', $request->method, $request->path));
        [$method, $path, $query, $result] = $next;
        Assert::assertSame($method . ' ' . $path, $request->method . ' ' . $request->path, 'Provider calls out of order');
        if ($query !== null) {
            Assert::assertSame($query, $request->query);
        }
        if ($result instanceof TransportFailure) {
            throw $result;
        }

        return $result;
    }

    public function assertScriptConsumed(): void
    {
        Assert::assertSame([], array_map(static fn (array $s): string => $s[0] . ' ' . $s[1], $this->script), 'Expected provider calls were not made');
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(static fn (array $s): string => $s['request']->method . ' ' . $s['request']->path, $this->sent);
    }
}
