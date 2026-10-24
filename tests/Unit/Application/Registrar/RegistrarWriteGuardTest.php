<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarAction;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Application\Registrar\RegistrarWritesDisabled;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** DR-4 / DR-T1: staging (Live key) can read but never spend or change a domain. */
final class RegistrarWriteGuardTest extends TestCase
{
    public function testWritesAreOnOnlyInProductionWithTheExplicitSetting(): void
    {
        self::assertTrue(RegistrarWriteGuard::writesEnabled('enabled', 'production'));
        self::assertFalse(RegistrarWriteGuard::writesEnabled('enabled', 'staging'), 'staging never writes, whatever the setting');
        self::assertFalse(RegistrarWriteGuard::writesEnabled('enabled', 'development'));
        self::assertFalse(RegistrarWriteGuard::writesEnabled('', 'production'), 'default is off');
        self::assertFalse(RegistrarWriteGuard::writesEnabled('true', 'production'));
        self::assertFalse(RegistrarWriteGuard::writesEnabled('Enabled', 'production'));
    }

    public function testReadsAlwaysPassAndWritesAreRefusedAndAudited(): void
    {
        $audit = new InMemoryAuditLog();
        $guard = new RegistrarWriteGuard(false, new AuditTrail($audit, new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger()));

        foreach (RegistrarAction::cases() as $action) {
            if (!$action->isWrite()) {
                $guard->check($action, 'domainnameapi');
                continue;
            }
            try {
                $guard->check($action, 'domainnameapi');
                self::fail($action->value . ' was not refused');
            } catch (RegistrarWritesDisabled $e) {
                self::assertSame($action, $e->action);
            }
        }

        $writes = array_values(array_filter(RegistrarAction::cases(), static fn (RegistrarAction $a): bool => $a->isWrite()));
        self::assertCount(count($writes), $audit->events);
        self::assertSame(['registrar.write_refused'], array_values(array_unique($audit->types())));
        self::assertSame(['provider' => 'domainnameapi', 'action' => 'register'], $audit->events[0]->details);
    }

    public function testEnabledGuardLetsWritesThroughWithoutAudit(): void
    {
        $audit = new InMemoryAuditLog();
        $guard = new RegistrarWriteGuard(true, new AuditTrail($audit, new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger()));

        $guard->check(RegistrarAction::Register, 'domainnameapi');

        self::assertSame([], $audit->events);
    }

    public function testEveryMoneyOrDomainChangingActionIsAWrite(): void
    {
        foreach (['register', 'renew', 'transfer', 'restore', 'name_servers', 'child_name_servers', 'contacts', 'transfer_lock', 'privacy', 'dns_zone', 'forwarding'] as $write) {
            self::assertTrue(RegistrarAction::from($write)->isWrite(), $write);
        }
        foreach (['price_list', 'availability', 'account_balance', 'domain_details'] as $read) {
            self::assertFalse(RegistrarAction::from($read)->isWrite(), $read);
        }
    }
}
