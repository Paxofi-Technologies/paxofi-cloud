<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Identity\SignIn\SignInAlerts;

final class AuditFixtures
{
    public const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    public static function trail(?InMemoryAuditLog $log = null): AuditTrail
    {
        return new AuditTrail($log ?? new InMemoryAuditLog(), new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), new RecordingLogger());
    }

    /** Sign-in alerts that queue into $outbox (or nowhere) and know no devices yet. */
    public static function alerts(?SignInStore $identities = null, ?RecordingOutbox $outbox = null, ?InMemoryKnownDevices $devices = null, ?ScriptedRateLimiter $limiter = null, ?InMemoryAuditLog $audit = null): SignInAlerts
    {
        return new SignInAlerts(
            $devices ?? new InMemoryKnownDevices(),
            $identities ?? new InMemoryIdentityStore(),
            $outbox ?? new RecordingOutbox(),
            $limiter ?? new ScriptedRateLimiter(),
            new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')),
            new RecordingLogger(),
            self::trail($audit),
        );
    }

    public static function client(string $ip = '198.51.100.7', string $userAgent = self::USER_AGENT): ClientContext
    {
        return ClientContext::from($ip, $userAgent, 'NG', 'Lagos', 'Lagos', 'req-12345678');
    }
}
