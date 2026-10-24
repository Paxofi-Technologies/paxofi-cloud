<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Namecheap;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapClient;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapEnvironment;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md NC-2, NC-3, NC-5, NC-8, NC-9 / DX-T2, DX-T12. */
final class NamecheapClientTest extends TestCase
{
    private const string API_KEY = 'nc0a1b2c3d4e5f60718293a4b5c6d7e8f'; // gitleaks:allow (fake test key)
    private const array ACTIONS = ['REGISTER' => 'register', 'RENEW' => 'renew', 'TRANSFER' => 'transfer', 'REACTIVATE' => 'reactivate'];

    private FakeProviderTransport $transport;
    private StaticCredentialSource $credentials;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
        $this->credentials = new StaticCredentialSource(['namecheap-staging' => json_encode([
            'api_user' => 'paxofi', 'username' => 'paxofi', 'api_key' => self::API_KEY, 'client_ip' => '8.8.4.4',
        ], JSON_THROW_ON_ERROR)]);
    }

    private function client(NamecheapEnvironment $environment = NamecheapEnvironment::Live): NamecheapClient
    {
        $trail = new AuditTrail(new InMemoryAuditLog(), new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger());

        return new NamecheapClient($this->transport, $this->credentials, 'namecheap-staging', $environment, new RegistrarWriteGuard(false, $trail));
    }

    /** @param array<string, list<string>> $products product => <Price/> elements */
    private static function sheet(string $category, array $products): string
    {
        $body = '';
        foreach ($products as $name => $prices) {
            $body .= '<Product Name="' . $name . '">' . implode('', $prices) . '</Product>';
        }

        return '<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse><UserGetPricingResult><ProductType Name="domains">'
            . '<ProductCategory Name="' . $category . '">' . $body . '</ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>';
    }

    private static function price(string $amount, int $years = 1, string $extra = ''): string
    {
        return '<Price Duration="' . $years . '" DurationType="YEAR" Price="' . $amount . '" ' . $extra . ' Currency="USD" />';
    }

    /** @param array<string, array<string, list<string>>> $byAction ACTION => products */
    private function script(array $byAction): void
    {
        foreach (self::ACTIONS as $action => $category) {
            $this->transport->expectRaw('POST', '/xml.response', 200, self::sheet($category, $byAction[$action] ?? []));
        }
    }

    public function testFourCallsWithTheCredentialOnlyInThePostBody(): void
    {
        $this->script([
            'REGISTER' => ['com' => [self::price('9.98'), self::price('19.96', 2)], 'NET' => [self::price('12.98')]],
            'RENEW' => ['com' => [self::price('14.58')], 'net' => [self::price('15.98')]],
            'TRANSFER' => ['com' => [self::price('10.98')]],
            'REACTIVATE' => ['com' => [self::price('80.00')]],
        ]);

        $list = $this->client()->fetchAll();

        $this->transport->assertScriptConsumed();
        self::assertSame('live', $list->environment);
        self::assertSame(['com', 'net'], array_keys($list->items));
        $com = $list->items['com'];
        self::assertSame([998, 1458, 1098, 8000], [$com->register->minor, $com->renew->minor, $com->transfer?->minor, $com->restore?->minor]);
        self::assertSame([1, 2], [$com->minYears, $com->maxYears]);
        self::assertNull($list->items['net']->transfer, 'DR-15: a missing optional price is empty, not a failure');

        foreach ($this->transport->sent as $i => $sent) {
            $request = $sent['request'];
            self::assertSame('https://api.namecheap.com', $sent['baseUrl']);
            self::assertSame([], $request->query, 'nothing travels in the URL');
            self::assertSame(self::API_KEY, $request->form['ApiKey'] ?? null);
            self::assertSame('8.8.4.4', $request->form['ClientIp'] ?? null);
            self::assertSame('namecheap.users.getPricing', $request->form['Command'] ?? null);
            self::assertSame('DOMAIN', $request->form['ProductType'] ?? null);
            self::assertSame(array_keys(self::ACTIONS)[$i], $request->form['ActionName'] ?? null);
            self::assertNotSame(self::API_KEY, $sent['secret'], 'the transport credential is only a client tag');
            self::assertStringNotContainsString(self::API_KEY, print_r($request, true), 'the form is redacted in debug output');
        }
    }

    public function testItemsThatCannotBeSoldAreSkippedWithAReason(): void
    {
        $this->script([
            'REGISTER' => [
                'ai' => [self::price('150.00', 2)],
                'zero' => [self::price('0.0')],
                'norenew' => [self::price('9.00')],
                'bad_name!' => [self::price('9.00')],
                'promo' => [self::price('1.98', 1, 'YourPrice="10.98" PromotionPrice="1.98"')],
            ],
            'RENEW' => ['ai' => [self::price('150.00', 2)], 'zero' => [self::price('9.00')], 'promo' => [self::price('12.00')], 'bad_name!' => [self::price('1.00')]],
        ]);

        $list = $this->client()->fetchAll();

        self::assertSame(['promo'], array_keys($list->items));
        self::assertSame('promo', $list->items['promo']->providerStatus, 'NC-6: promotional cost flagged');
        self::assertSame(1098, $list->items['promo']->register->minor, 'NC-6: never the promotional price');
        $skipped = [];
        foreach ($list->skipped as $s) {
            $skipped[$s->name ?? '?'] = $s->reason . ($s->detail === null ? '' : ' ' . $s->detail);
        }
        self::assertSame([
            'ai' => 'no_register_price periods:2',
            'zero' => 'bad_price reg:zero',
            'norenew' => 'no_renew_price',
            '?' => 'bad_name',
        ], $skipped);
        self::assertSame(['bad_name' => 1, 'bad_price' => 1, 'no_register_price' => 1, 'no_renew_price' => 1], $list->refused);
    }

    public function testFieldInventoryRecordsAttributeNamesNotValues(): void
    {
        $this->script(['REGISTER' => ['com' => [self::price('9.98')]], 'RENEW' => ['com' => [self::price('9.98')]]]);

        $paths = array_map(static fn ($f): string => $f->path, $this->client()->fetchAll()->fields);

        self::assertContains('prices[].register.Price', $paths);
        self::assertContains('prices[].renew.Duration', $paths);
    }

    public function testSandboxIsRecordedAndRefusedInProduction(): void
    {
        $this->script([]);
        self::assertSame('sandbox', $this->client(NamecheapEnvironment::Sandbox)->fetchAll()->environment);
        self::assertSame('https://api.sandbox.namecheap.com', $this->transport->sent[0]['baseUrl']);

        $this->expectException(\InvalidArgumentException::class);
        NamecheapEnvironment::fromSetting('sandbox', 'production');
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function failures(): iterable
    {
        $error = static fn (string $n): string => '<?xml version="1.0"?><ApiResponse Status="ERROR"><Errors><Error Number="' . $n . '">x</Error></Errors></ApiResponse>';
        yield 'IP not whitelisted' => [200, $error('1011150'), 'unauthorised'];
        yield 'bad API key' => [200, $error('1011102'), 'unauthorised'];
        yield 'other API error' => [200, $error('3050900'), 'provider_error'];
        yield 'HTTP 401' => [401, '', 'unauthorised'];
        yield 'HTTP 429' => [429, '', 'rate_limited'];
        yield 'HTTP 503' => [503, '', 'provider_error'];
        yield 'not XML' => [200, '{"oops":1}', 'malformed'];
    }

    #[DataProvider('failures')]
    public function testFailuresMapToFixedReasons(int $status, string $body, string $reason): void
    {
        $this->transport->expectRaw('POST', '/xml.response', $status, $body);

        try {
            $this->client()->fetchAll();
            self::fail('no failure');
        } catch (RegistrarUnavailable $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testMissingOrInvalidCredentialStopsBeforeAnyCall(): void
    {
        $this->credentials = new StaticCredentialSource([]);
        try {
            $this->client()->fetchAll();
            self::fail('no failure');
        } catch (RegistrarUnavailable $e) {
            self::assertSame('credential_missing', $e->reason);
        }

        foreach (['10.0.0.5', '127.0.0.1', '100.64.1.1', 'not-an-ip', '2001:4860::1'] as $ip) {
            $this->credentials = new StaticCredentialSource(['namecheap-staging' => json_encode(['api_user' => 'a', 'username' => 'a', 'api_key' => self::API_KEY, 'client_ip' => $ip], JSON_THROW_ON_ERROR)]);
            try {
                $this->client()->fetchAll();
                self::fail($ip . ' accepted');
            } catch (RegistrarUnavailable $e) {
                self::assertSame('credential_invalid', $e->reason, $ip);
            }
        }
        self::assertSame([], $this->transport->sent);
    }
}
