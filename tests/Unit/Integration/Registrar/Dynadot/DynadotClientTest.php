<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Dynadot;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Registrar\Dynadot\DynadotClient;
use PaxofiCloud\Integration\Registrar\Dynadot\DynadotEnvironment;
use PaxofiCloud\Integration\Registrar\Dynadot\DynadotSignature;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md DY-1 to DY-9 / DY-T1, DY-T4. */
final class DynadotClientTest extends TestCase
{
    private const string API_KEY = 'dyn-key-0a1b2c3d4e5f60718293'; // gitleaks:allow (fake test key)
    private const string API_SECRET = 'dyn-secret-9f8e7d6c5b4a'; // gitleaks:allow (fake test secret)

    private FakeProviderTransport $transport;
    /** @var list<int> */
    private array $pauses = [];
    private int $ids = 0;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
    }

    private function client(DynadotEnvironment $environment = DynadotEnvironment::Live, ?string $stored = null): DynadotClient
    {
        $credentials = new StaticCredentialSource(['dynadot-staging' => $stored ?? json_encode(['api_key' => self::API_KEY, 'api_secret' => self::API_SECRET], JSON_THROW_ON_ERROR)]);
        $trail = new AuditTrail(new InMemoryAuditLog(), new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger());

        return new DynadotClient(
            $this->transport,
            $credentials,
            'dynadot-staging',
            $environment,
            new RegistrarWriteGuard(false, $trail),
            function (int $ms): void {
                $this->pauses[] = $ms;
            },
            fn (): string => sprintf('00000000-0000-4000-8000-%012d', ++$this->ids),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function page(array $rows, ?int $page = null, ?int $pageSize = null): array
    {
        $data = ['currency' => 'USD', 'tld_price_list' => $rows];
        if ($page !== null) {
            $data['page'] = $page;
        }
        if ($pageSize !== null) {
            $data['page_size'] = $pageSize;
        }

        return ['code' => 200, 'message' => 'success', 'data' => $data];
    }

    /** @return array<string, mixed> */
    private static function row(string $tld, string $register = '10.88', string $renew = '10.88'): array
    {
        return ['tld' => $tld, 'all_years_register_price' => [$register], 'all_years_renew_price' => [$renew], 'transfer_price' => '9.99', 'min_duration' => 1, 'max_duration' => 10];
    }

    public function testSignedBearerRequestsWithTheKeyNeverInTheUrl(): void
    {
        $this->transport->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('.com'), self::row('.xyz', '1.99', '14.45')]), ['currency' => 'usd']);

        $list = $this->client()->fetchAll();

        $this->transport->assertScriptConsumed();
        self::assertSame(['com', 'xyz'], array_values(array_map(static fn ($i): string => $i->tld->value, $list->items)));
        self::assertSame('promo', array_values($list->items)[1]->providerStatus);
        self::assertSame('live', $list->environment);

        $sent = $this->transport->sent[0];
        self::assertSame('https://api.dynadot.com/restful/v2', $sent['baseUrl']);
        self::assertSame(self::API_KEY, $sent['secret'], 'DY-1: the key travels as the bearer token');
        self::assertSame('dynadot-staging', $sent['credentialId']);
        $headers = $sent['request']->headers;
        self::assertSame('00000000-0000-4000-8000-000000000001', $headers['X-Request-ID']);
        self::assertSame(DynadotSignature::compute(self::API_KEY, self::API_SECRET, '/restful/v2/domains/get_tld_price?currency=usd', $headers['X-Request-ID']), $headers['X-Signature']);
        $url = DynadotClient::endpoint(DynadotEnvironment::Live)->url($sent['request']->path, $sent['request']->query);
        self::assertStringNotContainsString(self::API_KEY, $url);
        self::assertStringNotContainsString(self::API_SECRET, $url . json_encode($headers) . print_r($sent['request'], true));
        self::assertSame([], $this->pauses);
    }

    public function testTheBearerSchemeIsUsed(): void
    {
        self::assertSame('Authorization: Bearer abc', DynadotClient::endpoint(DynadotEnvironment::Live)->authorization('abc'));
        self::assertInstanceOf(ProviderEndpoint::class, DynadotClient::endpoint(DynadotEnvironment::Sandbox));
        self::assertSame('https://api-sandbox.dynadot.com/restful/v2', DynadotEnvironment::Sandbox->baseUrl());
    }

    public function testPagesFollowTheServerOneAtATimeWithAPause(): void
    {
        $this->transport
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('com'), self::row('net')], 1, 2), ['currency' => 'usd'])
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('org'), self::row('bad', '0')], 2, 2), ['currency' => 'usd', 'page_index' => 2, 'count_per_page' => 2])
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('site')], 3, 2), ['currency' => 'usd', 'page_index' => 3, 'count_per_page' => 2]);

        $list = $this->client()->fetchAll();

        $this->transport->assertScriptConsumed();
        self::assertSame(['com', 'net', 'org', 'site'], array_values(array_map(static fn ($i): string => $i->tld->value, $list->items)));
        self::assertSame(['bad_price' => 1], $list->refused);
        self::assertSame([DynadotClient::PAUSE_MS, DynadotClient::PAUSE_MS], $this->pauses);
        // Each call carries its own request ID and signature over its own query.
        $last = $this->transport->sent[2];
        self::assertSame(
            DynadotSignature::compute(self::API_KEY, self::API_SECRET, '/restful/v2/domains/get_tld_price?currency=usd&page_index=3&count_per_page=2', $last['request']->headers['X-Request-ID']),
            $last['request']->headers['X-Signature'],
        );
    }

    public function testAPageThatRepeatsTheLastStopsPaging(): void
    {
        $this->transport
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('com'), self::row('net')], 1, 2))
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('com'), self::row('net')], 1, 2));

        $list = $this->client()->fetchAll();

        $this->transport->assertScriptConsumed();
        self::assertSame(['duplicate' => 2], $list->refused);
    }

    public function testA429WaitsAndRetriesThenGivesUp(): void
    {
        $this->transport
            ->expect('GET', '/domains/get_tld_price', 429)
            ->expect('GET', '/domains/get_tld_price', 200, self::page([self::row('com')]));
        self::assertCount(1, $this->client()->fetchAll()->items);
        self::assertSame([2000], $this->pauses);

        $this->pauses = [];
        for ($i = 0; $i < 4; $i++) {
            $this->transport->expect('GET', '/domains/get_tld_price', 429);
        }
        try {
            $this->client()->fetchAll();
            self::fail('Expected rate_limited');
        } catch (RegistrarUnavailable $e) {
            self::assertSame('rate_limited', $e->reason);
        }
        self::assertSame([2000, 4000, 8000], $this->pauses);
        $this->transport->assertScriptConsumed();
    }

    /** @return iterable<string, array{int, array<string, mixed>|null, string}> */
    public static function failures(): iterable
    {
        yield 'http 401' => [401, null, 'unauthorised'];
        yield 'http 403' => [403, null, 'unauthorised'];
        yield 'body code 401' => [200, ['code' => 401, 'message' => 'Invalid API key ' . self::API_KEY], 'unauthorised'];
        yield 'body code 500' => [200, ['code' => 500, 'message' => 'oops'], 'provider_error'];
        yield 'http 500' => [500, null, 'provider_error'];
        yield 'no data' => [200, ['code' => 200], 'malformed'];
        yield 'list missing' => [200, ['code' => 200, 'data' => ['currency' => 'USD']], 'malformed'];
        yield 'other currency' => [200, ['code' => 200, 'data' => ['currency' => 'EUR', 'tld_price_list' => []]], 'malformed'];
    }

    /** @param array<string, mixed>|null $body */
    #[DataProvider('failures')]
    public function testFailuresBecomeFixedReasonsWithoutProviderText(int $status, ?array $body, string $reason): void
    {
        $this->transport->expect('GET', '/domains/get_tld_price', $status, $body);

        try {
            $this->client()->fetchAll();
            self::fail('Expected ' . $reason);
        } catch (RegistrarUnavailable $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString('Invalid', $e->getMessage());
        }
    }

    public function testABadStoredCredentialIsRefusedBeforeAnyCall(): void
    {
        foreach (['not-json', '{"api_key":"abcdefgh12"}', '{"api_key":"short","api_secret":"abcdefgh12"}', '{"api_secret":"abcdefgh12","api_key":"abcdefgh12"}'] as $stored) {
            try {
                $this->client(DynadotEnvironment::Live, $stored)->fetchAll();
                self::fail('Expected credential_invalid');
            } catch (RegistrarUnavailable $e) {
                self::assertSame('credential_invalid', $e->reason);
            }
        }
        self::assertSame([], $this->transport->sent);
    }

    public function testProductionNeverUsesTheSandbox(): void
    {
        self::assertSame(DynadotEnvironment::Live, DynadotEnvironment::fromSetting('', 'production'));
        self::assertSame(DynadotEnvironment::Sandbox, DynadotEnvironment::fromSetting('sandbox', 'staging'));
        $this->expectException(\InvalidArgumentException::class);
        DynadotEnvironment::fromSetting('sandbox', 'production');
    }
}
