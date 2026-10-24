<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiClient;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiEnvironment;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-REGISTRAR.md DR-2, DR-3, DR-6, DR-9 / DR-T3, DR-T8, DR-T9. */
final class DomainNameApiClientTest extends TestCase
{
    private const string RESELLER = '7f3c2a10-1b2c-4d5e-8f90-123456789ee2';
    private const string API_KEY = 'live-key-Zq7xWm3pR9tL2vB8'; // gitleaks:allow (fake test key)

    private FakeProviderTransport $transport;
    private StaticCredentialSource $credentials;
    private InMemoryAuditLog $audit;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
        $this->credentials = new StaticCredentialSource(['domainnameapi-staging' => json_encode(['reseller_id' => self::RESELLER, 'api_key' => self::API_KEY], JSON_THROW_ON_ERROR)]);
        $this->audit = new InMemoryAuditLog();
    }

    private function client(DomainNameApiEnvironment $environment = DomainNameApiEnvironment::Live): DomainNameApiClient
    {
        $trail = new AuditTrail($this->audit, new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger());

        return new DomainNameApiClient($this->transport, $this->credentials, 'domainnameapi-staging', $environment, new RegistrarWriteGuard(false, $trail));
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $count, int $offset = 0): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'name' => 'tld' . ($offset + $i),
                'status' => 'Active',
                'prices' => [['register' => ['price' => '9.99', 'currency' => 'USD', 'period' => 1], 'renew' => ['price' => '10.99', 'currency' => 'USD', 'period' => 1]]],
            ];
        }

        return $items;
    }

    public function testFetchesEveryPageWithTheTwoAuthHeadersOnTheLiveGateway(): void
    {
        $this->transport
            ->expect('GET', '/products/tlds', 200, ['items' => self::items(100), 'totalCount' => 150], ['MaxResultCount' => 100, 'SkipCount' => 0])
            ->expect('GET', '/products/tlds', 200, ['items' => self::items(50, 100), 'totalCount' => 150], ['MaxResultCount' => 100, 'SkipCount' => 100]);

        $list = $this->client()->fetchAll();

        $this->transport->assertScriptConsumed();
        self::assertCount(150, $list->items);
        self::assertSame('live', $list->environment);
        self::assertSame(999, $list->items['tld0']->register->minor);
        foreach ($this->transport->sent as $sent) {
            self::assertSame('https://api.domainresellerapi.com/api/v1', $sent['baseUrl']);
            self::assertSame(['__reseller' => self::RESELLER], $sent['request']->headers);
            self::assertSame(self::API_KEY, $sent['secret'], 'only the API key is the credential header value');
        }
        self::assertSame('X-API-KEY: ' . self::API_KEY, DomainNameApiClient::endpoint(DomainNameApiEnvironment::Live)->authorization(self::API_KEY));
    }

    public function testStopsAtAShortPageEvenWithoutATotal(): void
    {
        $this->transport->expect('GET', '/products/tlds', 200, ['items' => self::items(3)]);

        self::assertCount(3, $this->client()->fetchAll()->items);
        $this->transport->assertScriptConsumed();
    }

    public function testCountsRefusedAndDuplicateItemsWithoutStoppingTheRun(): void
    {
        $items = self::items(2);
        $items[] = $items[0];
        $items[] = ['name' => 'bad name'];
        $items[] = ['name' => 'try', 'prices' => [['register' => ['price' => '1', 'currency' => 'TRY'], 'renew' => ['price' => '1', 'currency' => 'USD']]]];
        $this->transport->expect('GET', '/products/tlds', 200, ['items' => $items]);

        $list = $this->client()->fetchAll();

        self::assertSame(['tld0', 'tld1'], array_keys($list->items));
        self::assertSame(['bad_name' => 1, 'duplicate' => 1, 'unsupported_currency' => 1], $list->refused);
        self::assertSame(3, $list->refusedTotal());
    }

    public function testStopsAfterThePageLimitWithoutAResult(): void
    {
        for ($page = 0; $page < DomainNameApiClient::MAX_PAGES; $page++) {
            $this->transport->expect('GET', '/products/tlds', 200, ['items' => self::items(100, $page * 100)]);
        }

        $this->expectExceptionObject(new RegistrarUnavailable('too_many_pages'));
        $this->client()->fetchAll();
    }

    public function testUsesTheTestGatewayWhenConfigured(): void
    {
        $this->transport->expect('GET', '/products/tlds', 200, ['items' => []]);

        $list = $this->client(DomainNameApiEnvironment::Ote)->fetchAll();

        self::assertSame('https://ote.domainresellerapi.com/api/v1', $this->transport->sent[0]['baseUrl']);
        self::assertSame('ote', $list->environment);
    }

    /** @return iterable<string, array{int, string}> */
    public static function failures(): iterable
    {
        yield 'unauthorised' => [401, 'unauthorised'];
        yield 'forbidden' => [403, 'unauthorised'];
        yield 'redirect to login (bad key)' => [302, 'unauthorised'];
        yield 'rate limited' => [429, 'rate_limited'];
        yield 'server error' => [503, 'provider_error'];
        yield 'bad request' => [400, 'provider_error'];
    }

    #[DataProvider('failures')]
    public function testMapsHttpFailuresToFixedReasons(int $status, string $reason): void
    {
        $this->transport->expect('GET', '/products/tlds', $status, ['message' => 'Key ' . self::API_KEY . ' rejected for ' . self::RESELLER]);

        try {
            $this->client()->fetchAll();
            self::fail('expected a failure');
        } catch (RegistrarUnavailable $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage(), 'DR-T3: provider text never reaches errors');
            self::assertStringNotContainsString(self::RESELLER, $e->getMessage());
        }
    }

    public function testTransportAndShapeFailures(): void
    {
        $this->transport->expectFailure('GET', '/products/tlds')
            ->expectRaw('GET', '/products/tlds', 200, '<html>login</html>')
            ->expect('GET', '/products/tlds', 200, ['data' => []]);

        foreach (['transport', 'malformed', 'malformed'] as $reason) {
            try {
                $this->client()->fetchAll();
                self::fail('expected ' . $reason);
            } catch (RegistrarUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badCredentials(): iterable
    {
        yield 'missing' => [[]];
        yield 'old username/password shape' => [['domainnameapi-staging' => '{"username":"r1","password":"p"}']];
        yield 'not JSON' => [['domainnameapi-staging' => 'plain-key']];
        yield 'reseller ID with header injection' => [['domainnameapi-staging' => '{"reseller_id":"a\\r\\nX-Evil:1","api_key":"abcdefgh12"}']];
        yield 'short key' => [['domainnameapi-staging' => '{"reseller_id":"abc","api_key":"short"}']];
    }

    /** @param array<string, string> $secrets */
    #[DataProvider('badCredentials')]
    public function testRefusesUnusableCredentialsBeforeAnyCall(array $secrets): void
    {
        $this->credentials = new StaticCredentialSource($secrets);

        try {
            $this->client()->fetchAll();
            self::fail('expected a credential failure');
        } catch (RegistrarUnavailable $e) {
            self::assertTrue($e->needsNewCredential());
        }
        self::assertSame([], $this->transport->sent);
    }

    public function testProductionNeverUsesTheTestGateway(): void
    {
        self::assertSame(DomainNameApiEnvironment::Live, DomainNameApiEnvironment::fromSetting('', 'production'));
        self::assertSame(DomainNameApiEnvironment::Ote, DomainNameApiEnvironment::fromSetting('ote', 'staging'));
        $this->expectException(\InvalidArgumentException::class);
        DomainNameApiEnvironment::fromSetting('ote', 'production');
    }

    public function testUnknownEnvironmentSettingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainNameApiEnvironment::fromSetting('https://evil.example', 'staging');
    }
}
