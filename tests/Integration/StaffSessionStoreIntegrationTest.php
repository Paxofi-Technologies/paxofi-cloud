<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Staff\Session\StaffSession;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Session\RedisStaffSessionStore;

/** The staff session index on Redis 7 (identity threat model §6.25). */
final class StaffSessionStoreIntegrationTest extends IntegrationTestCase
{
    private RedisClient $redis;
    private RedisStaffSessionStore $store;
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = Resolve::get(AppFactory::create(self::basePath())->container(), RedisClient::class);
        // A prefix of its own per run, so parallel or repeated runs never see each other's keys.
        $this->prefix = 'pc:test:staffsess:' . bin2hex(random_bytes(4)) . ':';
        $this->store = new RedisStaffSessionStore($this->redis, $this->prefix);
    }

    private static function session(string $staffId, string $ip = '203.0.113.45'): StaffSession
    {
        $now = new \DateTimeImmutable('2026-10-24T15:00:00.123456Z');

        return new StaffSession(hash('sha256', random_bytes(16)), $staffId, 'csrf-' . bin2hex(random_bytes(4)), $now, $now, $ip, 'Mozilla/5.0 Test', 'Ikeja, Lagos, Nigeria');
    }

    public function testListsAMembersSessionsWithTheirClientFields(): void
    {
        $staff = str_repeat('a', 32);
        $one = self::session($staff);
        $two = self::session($staff, '198.51.100.9');
        $other = self::session(str_repeat('b', 32));
        foreach ([$one, $two, $other] as $s) {
            $this->store->save($s, 60);
        }

        $listed = $this->store->forStaff($staff);
        usort($listed, static fn (StaffSession $a, StaffSession $b): int => strcmp($a->clientIp, $b->clientIp));

        self::assertSame([$two->idHash, $one->idHash], array_map(static fn (StaffSession $s): string => $s->idHash, $listed));
        self::assertEquals($one, $this->store->find($one->idHash), 'IP, user agent, location and microsecond times round-trip');
        self::assertCount(1, $this->store->forStaff(str_repeat('b', 32)));
    }

    public function testDeletedOrExpiredRecordsLeaveTheIndexOnRead(): void
    {
        $staff = str_repeat('c', 32);
        $kept = self::session($staff);
        $gone = self::session($staff);
        $this->store->save($kept, 60);
        $this->store->save($gone, 60);
        $this->store->delete($gone->idHash);

        self::assertSame([$kept->idHash], array_map(static fn (StaffSession $s): string => $s->idHash, $this->store->forStaff($staff)));
        self::assertSame(1, $this->redis->evaluate("return redis.call('SCARD', KEYS[1])", [$this->prefix . 'idx:' . $staff], []), 'pruned on read');
    }

    public function testAnIndexEntryNeverShowsAnotherMembersSession(): void
    {
        $victim = self::session(str_repeat('d', 32));
        $this->store->save($victim, 60);
        // Plant the victim's key in the attacker's index directly.
        $this->redis->evaluate("return redis.call('SADD', KEYS[1], ARGV[1])", [$this->prefix . 'idx:' . str_repeat('e', 32)], [$victim->idHash]);

        self::assertSame([], $this->store->forStaff(str_repeat('e', 32)));
    }

    public function testTheIndexExpiresWithTheAbsoluteLifetime(): void
    {
        $staff = str_repeat('f', 32);
        $this->store->save(self::session($staff), 60);

        $ttl = $this->redis->evaluate("return redis.call('TTL', KEYS[1])", [$this->prefix . 'idx:' . $staff], []);
        self::assertIsInt($ttl);
        self::assertGreaterThan(28_000, $ttl);
        self::assertLessThanOrEqual(28_800, $ttl);
    }

    public function testRejectsUnexpectedStaffIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->forStaff('a b');
    }
}
