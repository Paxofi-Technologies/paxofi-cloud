<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Contracts\WhoisLookup;
use PaxofiCloud\Application\Domains\WhoisAvailability;
use PaxofiCloud\Application\Domains\WhoisServerDirectory;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-17, DS-19, DS-T16. */
final class WhoisAvailabilityTest extends TestCase
{
    /** @var list<string> */
    public array $asked = [];

    private function availability(ScriptedRateLimiter $limiter): WhoisAvailability
    {
        $lookup = new class ($this) implements WhoisLookup {
            public function __construct(private WhoisAvailabilityTest $test)
            {
            }

            public function query(string $server, string $query): string
            {
                if ($server === WhoisServerDirectory::IANA) {
                    return $query === 'ng' ? "whois: whois.nic.net.ng\n" : "domain: ZZ\n";
                }
                $this->test->asked[] = $server . ' ' . $query;

                return "No match for {$query}\n";
            }
        };

        return new WhoisAvailability($lookup, new WhoisServerDirectory($lookup, new InMemoryRedis(), $limiter), $limiter);
    }

    public function testAsksTheLearnedServerAndRespectsThePerServerLimit(): void
    {
        $limiter = new ScriptedRateLimiter();
        $whois = $this->availability($limiter);

        foreach (range(1, 4) as $i) {
            self::assertSame('available', $whois->check("paxofi{$i}.com.ng"));
        }
        self::assertSame('unknown', $whois->check('other.com.ng'), 'fifth query in the same second: no call');
        self::assertCount(4, $this->asked);
        self::assertSame('whois.nic.net.ng paxofi1.com.ng', $this->asked[0]);
    }

    public function testNoServerOrNoExtensionIsUnknown(): void
    {
        $whois = $this->availability(new ScriptedRateLimiter());

        self::assertSame('unknown', $whois->check('paxofi.zz'));
        self::assertSame('unknown', $whois->check('localhost'));
        self::assertSame([], $this->asked);
    }

    public function testAStaffPhraseAndFixedServerArePassedThrough(): void
    {
        $whois = $this->availability(new ScriptedRateLimiter());

        self::assertSame('available', $whois->check('paxofi.zz', 'whois.custom.example'));
        self::assertSame(['whois.custom.example paxofi.zz'], $this->asked);
    }
}
