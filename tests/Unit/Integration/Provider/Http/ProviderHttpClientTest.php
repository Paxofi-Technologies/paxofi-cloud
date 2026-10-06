<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\Http\HostResolver;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS SEC-012; threat model V-02, V-15 (ProviderHttpClientTlsTest / ProviderHttpClientAllowlistTest). */
final class ProviderHttpClientTest extends TestCase
{
    private const string BASE = 'https://api.example.com/v1';
    private const string SECRET = 'test-token-not-real-0123456789';

    /** @return iterable<string, array{string}> */
    public static function rejectedBaseUrls(): iterable
    {
        yield 'plain http' => ['http://api.example.com/v1'];
        yield 'IPv4 literal' => ['https://10.0.0.5/v1'];
        yield 'IPv6 literal' => ['https://[::1]/v1'];
        yield 'explicit port' => ['https://api.example.com:8443/v1'];
        yield 'credentials in URL' => ['https://user:pass@api.example.com/v1'];
        yield 'query' => ['https://api.example.com/v1?x=1'];
        yield 'single-label host' => ['https://localhost/v1'];
        yield 'traversal prefix' => ['https://api.example.com/../v1'];
    }

    #[DataProvider('rejectedBaseUrls')]
    public function testEndpointRejectsUnsafeBaseUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderEndpoint($url);
    }

    public function testEndpointBuildsUrlsWithEncodedQuery(): void
    {
        $endpoint = new ProviderEndpoint(self::BASE);

        self::assertSame(
            'https://api.example.com/v1/servers?label_selector=paxo-svc%3D%3D42%2Cpaxo-env%3D%3Dprod',
            $endpoint->url('/servers', ['label_selector' => 'paxo-svc==42,paxo-env==prod']),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public IPv4' => ['8.8.8.8', true];
        yield 'public IPv6' => ['2606:4700::1111', true];
        yield 'RFC 1918' => ['10.0.0.1', false];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'cloud metadata' => ['169.254.169.254', false];
        yield 'CGNAT' => ['100.64.1.1', false];
        yield 'TEST-NET-1' => ['192.0.2.1', false];
        yield 'TEST-NET-3' => ['203.0.113.5', false];
        yield 'benchmarking' => ['198.18.0.1', false];
        yield 'IETF protocol assignments' => ['192.0.0.8', false];
        yield 'multicast' => ['224.0.0.1', false];
        yield 'reserved 240/4' => ['240.0.0.1', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'IPv6 loopback' => ['::1', false];
        yield 'IPv6 ULA' => ['fd00::1', false];
        yield 'IPv6 link-local' => ['fe80::1', false];
        yield 'IPv6 documentation' => ['2001:db8::1', false];
        yield 'IPv4-mapped private' => ['::ffff:10.0.0.1', false];
        yield 'NAT64 prefix' => ['64:ff9b::a00:1', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyPublicAddressesAreAllowed(string $ip, bool $public): void
    {
        self::assertSame($public, ProviderHttpClient::isPublicAddress($ip));
    }

    public function testRefusesAHostThatResolvesToAnyPrivateAddress(): void
    {
        $client = $this->client(['8.8.8.8', '10.0.0.7']);

        $this->expectException(TransportFailure::class);
        $this->expectExceptionMessage('non-public address');
        $client->vettedAddresses('api.example.com');
    }

    public function testRefusesAHostThatDoesNotResolve(): void
    {
        $this->expectException(TransportFailure::class);

        $this->client([])->vettedAddresses('api.example.com');
    }

    public function testRefusesNonAllowlistedBaseUrlBeforeResolvingOrConnecting(): void
    {
        $resolver = new RecordingResolver(['8.8.8.8']);
        $client = new ProviderHttpClient([new ProviderEndpoint(self::BASE)], $resolver);

        try {
            $client->send('https://evil.example.net/v1', ProviderRequest::get('/servers'), $this->credential());
            self::fail('Expected TransportFailure');
        } catch (TransportFailure $e) {
            self::assertStringContainsString('not allow-listed', $e->getMessage());
            self::assertSame([], $resolver->calls);
        }
    }

    public function testSendRefusesPrivateResolutionWithoutConnecting(): void
    {
        $this->expectException(TransportFailure::class);
        $this->expectExceptionMessage('non-public address');

        $this->client(['169.254.169.254'])->send(self::BASE, ProviderRequest::get('/servers'), $this->credential());
    }

    public function testCurlOptionsEnforceTlsNoRedirectsPinningAndNoProxy(): void
    {
        $client = $this->client(['8.8.8.8']);
        $options = $client->curlOptions(new ProviderEndpoint(self::BASE), ProviderRequest::get('/actions/1'), $this->credential(), ['8.8.8.8', '2606:4700::1111']);

        self::assertSame('https://api.example.com/v1/actions/1', $options[CURLOPT_URL]);
        self::assertSame('https', $options[CURLOPT_PROTOCOLS_STR]);
        self::assertSame('https', $options[CURLOPT_REDIR_PROTOCOLS_STR]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
        self::assertSame(['api.example.com:443:8.8.8.8,[2606:4700::1111]'], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertSame(3_000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(10_000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options, 'GET requests carry no body');
        self::assertIsArray($options[CURLOPT_HTTPHEADER]);
        self::assertContains('Authorization: Bearer ' . self::SECRET, $options[CURLOPT_HTTPHEADER]);
    }

    public function testJsonBodyIsEncodedWithContentType(): void
    {
        $options = $this->client(['8.8.8.8'])->curlOptions(
            new ProviderEndpoint(self::BASE),
            ProviderRequest::post('/servers', ['name' => 'paxo-svc-42', 'labels' => ['paxo-svc' => '42']]),
            $this->credential(),
            ['8.8.8.8'],
        );

        self::assertSame('{"name":"paxo-svc-42","labels":{"paxo-svc":"42"}}', $options[CURLOPT_POSTFIELDS]);
        self::assertIsArray($options[CURLOPT_HTTPHEADER]);
        self::assertContains('Content-Type: application/json', $options[CURLOPT_HTTPHEADER]);
        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
    }

    public function testConnectionFailuresBecomeTransportFailuresWithoutLeakingTheToken(): void
    {
        // 8.8.8.8:443 either refuses/times out (no egress) or answers with a
        // certificate for another name; both must fail closed.
        $client = new ProviderHttpClient([new ProviderEndpoint(self::BASE)], new RecordingResolver(['8.8.8.8']), 300, 1_000);

        try {
            $client->send(self::BASE, ProviderRequest::get('/servers'), $this->credential());
            self::fail('Expected TransportFailure');
        } catch (TransportFailure $e) {
            self::assertStringContainsString('curl error', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testRejectsUnsafeLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderHttpClient([new ProviderEndpoint(self::BASE)], new RecordingResolver([]), 3_000, 120_000);
    }

    /** @param list<string> $addresses */
    private function client(array $addresses): ProviderHttpClient
    {
        return new ProviderHttpClient([new ProviderEndpoint(self::BASE)], new RecordingResolver($addresses));
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('test', self::SECRET);
    }
}

final class RecordingResolver implements HostResolver
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        $this->calls[] = $host;

        return $this->addresses;
    }
}
