<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Whois;

use PaxofiCloud\Application\Domains\WhoisUnavailable;
use PaxofiCloud\Integration\Provider\Http\HostResolver;
use PaxofiCloud\Integration\Whois\WhoisClient;
use PaxofiCloud\Integration\Whois\WhoisSocket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-16, DS-T13, DS-T15, DS-T18. */
final class WhoisClientTest extends TestCase
{
    /** @var list<array{ip: string, payload: string, connect: int, total: int, max: int}> */
    public array $calls = [];

    /** @param array<string, list<string>> $dns */
    private function client(array $dns, string $reply = "No match for X\n"): WhoisClient
    {
        $socket = new class ($this, $reply) implements WhoisSocket {
            public function __construct(private WhoisClientTest $test, private string $reply)
            {
            }

            public function exchange(string $ip, string $payload, int $connectTimeoutMs, int $totalTimeoutMs, int $maxBytes): string
            {
                $this->test->calls[] = ['ip' => $ip, 'payload' => $payload, 'connect' => $connectTimeoutMs, 'total' => $totalTimeoutMs, 'max' => $maxBytes];

                return $this->reply;
            }
        };
        $resolver = new class ($dns) implements HostResolver {
            /** @param array<string, list<string>> $dns */
            public function __construct(private array $dns)
            {
            }

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        return new WhoisClient($socket, $resolver);
    }

    public function testQueriesThePinnedPublicAddressOnPort43WithLimits(): void
    {
        $reply = $this->client(['whois.nic.net.ng' => ['196.216.1.10']])->query('whois.nic.net.ng', 'paxofi.com.ng');

        self::assertSame("No match for X\n", $reply);
        self::assertSame([['ip' => '196.216.1.10', 'payload' => "paxofi.com.ng\r\n", 'connect' => 3_000, 'total' => 5_000, 'max' => 32_768]], $this->calls);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refused(): iterable
    {
        yield 'IP literal server' => ['10.0.0.1', 'example.ng', WhoisUnavailable::INVALID_SERVER];
        yield 'server with port' => ['whois.example.ng:4444', 'example.ng', WhoisUnavailable::INVALID_SERVER];
        yield 'CRLF injection' => ['whois.nic.net.ng', "example.ng\r\nhelp", WhoisUnavailable::INVALID_QUERY];
        yield 'space in query' => ['whois.nic.net.ng', 'example.ng -T dn', WhoisUnavailable::INVALID_QUERY];
        yield 'upper case query' => ['whois.nic.net.ng', 'EXAMPLE.ng', WhoisUnavailable::INVALID_QUERY];
        yield 'does not resolve' => ['whois.nowhere.example', 'example.ng', WhoisUnavailable::NOT_RESOLVED];
        yield 'resolves to a private address' => ['whois.internal.example', 'example.ng', WhoisUnavailable::NOT_PUBLIC];
        yield 'one address private' => ['whois.mixed.example', 'example.ng', WhoisUnavailable::NOT_PUBLIC];
        yield 'empty reply' => ['whois.silent.example', 'example.ng', WhoisUnavailable::EMPTY];
    }

    #[DataProvider('refused')]
    public function testRefusesWithoutConnectingWhereItMust(string $server, string $query, string $reason): void
    {
        $client = $this->client([
            'whois.nic.net.ng' => ['196.216.1.10'],
            'whois.internal.example' => ['127.0.0.1'],
            'whois.mixed.example' => ['196.216.1.10', '169.254.169.254'],
            'whois.silent.example' => ['196.216.1.11'],
        ], $server === 'whois.silent.example' ? "\r\n" : "No match\n");

        try {
            $client->query($server, $query);
            self::fail('Expected a refusal.');
        } catch (WhoisUnavailable $e) {
            self::assertSame($reason, $e->reason);
        }
        if ($reason !== WhoisUnavailable::EMPTY) {
            self::assertSame([], $this->calls, 'no connection was made');
        }
    }
}
