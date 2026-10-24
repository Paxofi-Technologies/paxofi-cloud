<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;

/**
 * Stops every registrar call that spends money or changes a domain unless
 * writes are explicitly enabled (DR-4). Staging uses the Live API key (CEO
 * decision, 24 Oct 2026) so it can read real cost prices; this guard is what
 * keeps it from registering, renewing or transferring anything.
 *
 * Adapters call check() before building any HTTP request. Reads pass freely.
 */
final readonly class RegistrarWriteGuard
{
    public function __construct(private bool $writesEnabled, private AuditTrail $audit)
    {
    }

    /**
     * Writes are on only when the setting says "enabled" **and** this is
     * production; any other value, or any other environment, means off.
     */
    public static function writesEnabled(string $setting, string $appEnv): bool
    {
        return $setting === 'enabled' && $appEnv === 'production';
    }

    /** @throws RegistrarWritesDisabled */
    public function check(RegistrarAction $action, string $provider): void
    {
        if (!$action->isWrite() || $this->writesEnabled) {
            return;
        }
        $this->audit->record(AuditEventType::REGISTRAR_WRITE_REFUSED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), details: [
            'provider' => $provider,
            'action' => $action->value,
        ]);

        throw new RegistrarWritesDisabled($action);
    }
}
