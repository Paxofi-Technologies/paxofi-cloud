<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Contracts\WhoisLookup;
use PaxofiCloud\Application\Domains\WhoisServerDirectory;
use PaxofiCloud\Application\Domains\WhoisUnavailable;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-15, DS-19, DS-T13. */
final class WhoisServerDirectoryTest extends TestCase
{
    /** @var list<array{string, string}> */
    public array $queries = [];
    /** @var array<string, string|null> zone => IANA reply, null = unreachable */
    public array $iana = [];

    private InMemoryRedis $redis;
    private ScriptedRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->limiter = new ScriptedRateLimiter();
    }

    private function directory(): WhoisServerDirectory
    {
        $test = $this;
        $whois = new class ($test) implements WhoisLookup {
            public function __construct(private WhoisServerDirectoryTest $test)
            {
            }

            public function query(string $server, string $query): string
            {
                $this->test->queries[] = [$server, $query];
                $reply = $this->test->iana[$query] ?? null;

                return $reply ?? throw new WhoisUnavailable(WhoisUnavailable::TIMEOUT);
            }
        };

        return new WhoisServerDirectory($whois, $this->redis, $this->limiter);
    }

    public function testLearnsTheServerFromIanaForTheTopLevelZoneAndCachesIt(): void
    {
        $this->iana['ng'] = "domain: NG\nwhois: whois.nic.net.ng\n";

        self::assertSame('whois.nic.net.ng', $this->directory()->serverFor('com.ng'));
        self::assertSame('whois.nic.net.ng', $this->directory()->serverFor('ng'));
        self::assertSame([[WhoisServerDirectory::IANA, 'ng']], $this->queries, 'asked once, then cached');
    }

    public function testAFixedServerWinsAndAnInvalidOneIsIgnored(): void
    {
        $this->iana['ng'] = "whois: whois.nic.net.ng\n";

        self::assertSame('whois.custom.example', $this->directory()->serverFor('com.ng', 'whois.custom.example'));
        self::assertSame('whois.nic.net.ng', $this->directory()->serverFor('com.ng', '127.0.0.1'));
    }

    public function testKeepsTheLastGoodServerWhenIanaFailsAndRemembersNone(): void
    {
        $this->iana['ng'] = "whois: whois.nic.net.ng\n";
        self::assertSame('whois.nic.net.ng', $this->directory()->serverFor('ng'));
        $this->redis->delete('pc:whois:server:fresh:ng');
        unset($this->iana['ng']);

        self::assertSame('whois.nic.net.ng', $this->directory()->serverFor('ng'), 'IANA down: last good kept');

        $this->iana['zz'] = "domain: ZZ\n";
        self::assertNull($this->directory()->serverFor('zz'));
        self::assertNull($this->directory()->serverFor('zz'));
        self::assertCount(1, array_filter($this->queries, static fn (array $q): bool => $q[1] === 'zz'), 'no server is remembered for a day');
    }

    public function testIanaIsAskedAtMostTenTimesAnHour(): void
    {
        foreach (range(1, 12) as $i) {
            $this->directory()->serverFor('t' . $i);
        }

        self::assertCount(WhoisServerDirectory::IANA_PER_HOUR, $this->queries);
    }
}
