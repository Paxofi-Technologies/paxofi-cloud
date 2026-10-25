<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Rdap;

use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Rdap\RdapBootstrap;
use PaxofiCloud\Integration\Rdap\RdapLookup;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-2, DS-3 / DS-T1, DS-T3, DS-T6, DS-T7. */
final class RdapTest extends TestCase
{
    public function testTheBootstrapKeepsOnlySafeHttpsServers(): void
    {
        $map = RdapBootstrap::parse((string) json_encode(['services' => [
            [['com', 'net'], ['https://rdap.verisign.com/com/v1/']],
            [['ng'], ['http://insecure.example/', 'https://rdap.nic.net.ng/']],
            [['bad1'], ['https://10.0.0.1/rdap/']],
            [['bad2'], ['https://rdap.example:8443/']],
            [['bad3'], ['https://user:pw@rdap.example/']],
            [['bad4'], ['https://rdap.example/?q=1']],
            [['bad5'], ['https://localhost/']],
            [['BAD', '../x'], ['https://rdap.example/']],
            'nonsense',
        ]]));

        self::assertSame([
            'com' => 'https://rdap.verisign.com/com/v1',
            'net' => 'https://rdap.verisign.com/com/v1',
            'ng' => 'https://rdap.nic.net.ng',
        ], $map);
        self::assertNull(RdapBootstrap::parse('not json'));
        self::assertNull(RdapBootstrap::parse('{"services":[]}'));
    }

    public function testTheBootstrapIsFetchedFromIanaOnly(): void
    {
        $transport = (new FakeProviderTransport())->expectRaw('GET', '/dns.json', 200, (string) json_encode(['services' => [[['com'], ['https://rdap.verisign.com/com/v1/']]]]));
        self::assertSame(['com' => 'https://rdap.verisign.com/com/v1'], (new RdapBootstrap($transport))->fetch());
        self::assertSame('https://data.iana.org/rdap', $transport->sent[0]['baseUrl']);

        $failing = (new FakeProviderTransport())->expectRaw('GET', '/dns.json', 503, '');
        self::assertNull((new RdapBootstrap($failing))->fetch());
    }

    public function testOnlyTheStatusDecidesAndAnythingElseIsUnknown(): void
    {
        $cases = [[200, 'taken'], [404, 'available'], [429, 'unknown'], [500, 'unknown'], [301, 'unknown']];
        foreach ($cases as [$status, $expected]) {
            $transport = (new FakeProviderTransport())->expectRaw('GET', '/domain/example.com', $status, '{"huge":"ignored"}');
            $lookup = new RdapLookup(static function (ProviderEndpoint $endpoint) use ($transport): FakeProviderTransport {
                self::assertSame('rdap.verisign.com', $endpoint->host, 'one server allow-listed per call');

                return $transport;
            });
            self::assertSame($expected, $lookup->check('example.com', 'https://rdap.verisign.com/com/v1'), (string) $status);
        }

        $failing = (new FakeProviderTransport())->expectFailure('GET', '/domain/example.com');
        self::assertSame('unknown', (new RdapLookup(static fn (): FakeProviderTransport => $failing))->check('example.com', 'https://rdap.verisign.com/com/v1'));
        self::assertSame('unknown', (new RdapLookup())->check('example.com', 'http://not-https.example'), 'a bad server is never called');
    }
}
