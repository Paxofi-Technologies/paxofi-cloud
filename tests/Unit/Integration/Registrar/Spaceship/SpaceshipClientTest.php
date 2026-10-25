<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Spaceship;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Integration\Registrar\Spaceship\SpaceshipClient;
use PaxofiCloud\Integration\Registrar\Spaceship\SpaceshipCredential;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md SP-1 to SP-3, SP-7 / SP-T4, SP-T5. */
final class SpaceshipClientTest extends TestCase
{
    private const string API_KEY = 'ss-key-0a1b2c3d4e5f60718293'; // gitleaks:allow (fake test key)
    private const string API_SECRET = 'ss-secret-9f8e7d6c5b4a3210'; // gitleaks:allow (fake test secret)

    private FakeProviderTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
    }

    private function client(?string $stored = null): SpaceshipClient
    {
        $credentials = new StaticCredentialSource($stored === '' ? [] : ['spaceship-staging' => $stored ?? json_encode(['api_key' => self::API_KEY, 'api_secret' => self::API_SECRET], JSON_THROW_ON_ERROR)]);
        $trail = new AuditTrail(new InMemoryAuditLog(), new FixedClock(new \DateTimeImmutable('2026-10-25T06:00:00Z')), new RecordingLogger());

        return new SpaceshipClient($this->transport, $credentials, 'spaceship-staging', new RegistrarWriteGuard(false, $trail));
    }

    public function testOnePostWithTheSecretAsCredentialHeaderAndTheKeyBesideIt(): void
    {
        $this->transport->expectRaw('POST', '/domains/available', 200, '{"domains":[
            {"domain":"a-test.com","result":"available","premiumPricing":[{"operation":"register","price":10.99,"currency":"USD"},{"operation":"renew","price":12.5,"currency":"USD"}]},
            {"domain":"b-test.ng","result":"available","premiumPricing":[]}
        ]}');

        $answers = $this->client()->check(['a-test.com', 'b-test.ng']);

        $this->transport->assertScriptConsumed();
        $sent = $this->transport->sent[0];
        self::assertSame('https://spaceship.dev/api/v1', $sent['baseUrl']);
        self::assertSame(self::API_SECRET, $sent['secret'], 'SP-2: the secret is the endpoint credential');
        self::assertSame('spaceship-staging', $sent['credentialId']);
        self::assertSame(['X-API-Key' => self::API_KEY], $sent['request']->headers);
        self::assertSame(['domains' => ['a-test.com', 'b-test.ng']], $sent['request']->json);
        self::assertSame('X-API-Secret: abc', SpaceshipClient::endpoint()->authorization('abc'));

        $url = SpaceshipClient::endpoint()->url($sent['request']->path, $sent['request']->query);
        self::assertStringNotContainsString(self::API_KEY, $url);
        self::assertStringNotContainsString(self::API_SECRET, $url);
        $debug = print_r($sent['request'], true);
        self::assertStringNotContainsString(self::API_KEY, $debug, 'SP-T5: header values are not in debug output');

        self::assertCount(2, $answers);
        self::assertSame('a-test.com', $answers[0]->domain);
        self::assertSame('available', $answers[0]->result);
        self::assertSame(1099, $answers[0]->register?->minor);
        self::assertSame(['renew'], $answers[0]->otherPriced);
        self::assertNull($answers[1]->register);
        self::assertSame([], $answers[1]->otherPriced);
    }

    public function testPricesAreReadAsExactTextAndRoundedUpToTheCent(): void
    {
        $this->transport->expectRaw('POST', '/domains/available', 200, '{"domains":[{"domain":"a-test.com","result":"available","premiumPricing":[{"operation":"register","price":9.001,"currency":"usd"}]}]}');

        self::assertSame(901, $this->client()->check(['a-test.com'])[0]->register?->minor);
    }

    public function testUnusablePricesAndWordsAreNotTrusted(): void
    {
        $this->transport->expectRaw('POST', '/domains/available', 200, '{"domains":[
            {"domain":"A-TEST.COM","result":"<script>","premiumPricing":[
                {"operation":"register","price":0,"currency":"USD"},
                {"operation":"register","price":5,"currency":"EUR"},
                {"operation":"<b>","price":1,"currency":"USD"},
                {"operation":"transfer","price":"x","currency":"USD"},
                {"operation":"restore","price":80,"currency":"USD"},
                "nonsense"
            ]},
            {"domain":"not-asked.com","result":"available","premiumPricing":[{"operation":"register","price":1,"currency":"USD"}]},
            {"domain":"a-test.com","result":"taken"}
        ]}');

        $answers = $this->client()->check(['a-test.com']);

        self::assertCount(1, $answers, 'names we did not ask about, and repeats, are dropped');
        self::assertSame('other', $answers[0]->result);
        self::assertNull($answers[0]->register, 'zero and non-USD register prices are no price');
        self::assertSame(['restore'], $answers[0]->otherPriced, 'only short words with a readable price');
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function failures(): iterable
    {
        yield 'unauthorised' => [401, '{}', 'unauthorised'];
        yield 'missing scope' => [403, '{"detail":"forbidden"}', 'unauthorised'];
        yield 'redirect' => [302, '', 'unauthorised'];
        yield 'rate limited' => [429, '{}', 'rate_limited'];
        yield 'unprocessable' => [422, '{"detail":"bad"}', 'provider_error'];
        yield 'server error' => [500, '', 'provider_error'];
        yield 'not json' => [200, '<html>', 'malformed'];
        yield 'no list' => [200, '{"domains":{"a":1}}', 'malformed'];
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeFixedReasons(int $status, string $body, string $reason): void
    {
        $this->transport->expectRaw('POST', '/domains/available', $status, $body);
        try {
            $this->client()->check(['a-test.com']);
            self::fail('expected RegistrarUnavailable');
        } catch (RegistrarUnavailable $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString('forbidden', $e->getMessage());
            self::assertStringNotContainsString(self::API_SECRET, $e->getMessage() . $e->getTraceAsString());
        }
        self::assertCount(1, $this->transport->sent, 'a 429 is not retried');
    }

    public function testTransportFailureAndMissingOrBadCredential(): void
    {
        $this->transport->expectFailure('POST', '/domains/available');
        foreach ([[null, 'transport'], ['', 'credential_missing'], ['{"api_key":"short","api_secret":"x"}', 'credential_invalid'], ['not-json', 'credential_invalid']] as [$stored, $reason]) {
            try {
                $this->client($stored)->check(['a-test.com']);
                self::fail('expected RegistrarUnavailable');
            } catch (RegistrarUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertCount(1, $this->transport->sent, 'no call is made without a usable credential');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badNames(): iterable
    {
        yield 'none' => [[]];
        yield 'too many' => [array_map(static fn (int $i): string => "n{$i}-test.com", range(1, 21))];
        yield 'repeated' => [['a-test.com', 'a-test.com']];
        yield 'upper case' => [['A-test.com']];
        yield 'no dot' => [['localhost']];
        yield 'path' => [['a-test.com/x']];
    }

    /** @param list<string> $names */
    #[DataProvider('badNames')]
    public function testOnlyOneToTwentyPlainNamesAreSent(array $names): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client()->check($names);
        } finally {
            self::assertSame([], $this->transport->sent);
        }
    }

    public function testTheCredentialIsRedactedAndCannotBeSerialised(): void
    {
        $credential = SpaceshipCredential::from(new ProviderCredential('spaceship-staging', json_encode(['api_key' => self::API_KEY, 'api_secret' => self::API_SECRET], JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString(self::API_KEY, print_r($credential, true));
        self::assertStringNotContainsString(self::API_SECRET, print_r($credential, true));
        $this->expectException(\LogicException::class);
        serialize($credential);
    }
}
