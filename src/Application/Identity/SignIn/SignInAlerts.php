<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\KnownDevices;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;

/**
 * Security emails to the account owner (SRS IAM-017; threat model §6.15,
 * T-53..T-55; CEO decision 18 Oct 2026): every sign-in, a sign-in from a
 * new device instead of that, many failed sign-ins and MFA changes, each
 * with the device, approximate location, IP and time.
 *
 * Best effort: queued after the action, and a failure is logged, never
 * thrown, so an email problem can never block a sign-in.
 */
final readonly class SignInAlerts
{
    public const int SIGN_IN_ALERTS_PER_HOUR = 10;

    public function __construct(
        private KnownDevices $devices,
        private SignInStore $identities,
        private EmailOutbox $outbox,
        private RateLimiter $limiter,
        private Clock $clock,
        private Logger $logger,
        private AuditTrail $audit,
    ) {
    }

    /** After a session is issued. $deviceToken is the browser's device cookie (T-55). */
    public function signedIn(string $identityId, string $accountId, ClientContext $client, #[\SensitiveParameter] ?string $deviceToken): void
    {
        $this->safely('sign_in', function () use ($identityId, $accountId, $client, $deviceToken): void {
            $known = $deviceToken !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $deviceToken) === 1
                && !$this->devices->remember($identityId, hash('sha256', $deviceToken), $client->device()->label(), $this->clock->now());
            if (!$known) {
                $this->audit->record(AuditEventType::SIGN_IN_NEW_DEVICE, AuditEvent::SUCCESS, $client, $identityId, $accountId);
            }
            // T-54: a cap per identity so repeated sign-ins cannot flood the inbox.
            if ($this->limiter->hit('notify:sign-in:' . $identityId, self::SIGN_IN_ALERTS_PER_HOUR, 3600) > 0) {
                $this->logger->info('notify.sign_in_alert_capped', ['identity_id' => $identityId]);

                return;
            }
            $this->send($identityId, $known ? EmailTemplates::SIGN_IN_ALERT : EmailTemplates::NEW_DEVICE_ALERT, $client, []);
        });
    }

    /** T-07 counter (b) crossed its threshold: once per window. */
    public function manyFailedSignIns(string $identityId, ClientContext $client, int $attempts): void
    {
        $this->safely('sign_in_attempts', fn () => $this->send($identityId, EmailTemplates::SIGN_IN_ATTEMPTS_ALERT, $client, ['attempts' => (string) $attempts]));
    }

    /** @param key-of<EmailTemplates::SECURITY_CHANGES> $change */
    public function securityChanged(string $identityId, ClientContext $client, string $change): void
    {
        $this->safely('security_change', fn () => $this->send($identityId, EmailTemplates::SECURITY_CHANGE, $client, ['change' => $change]));
    }

    /** @param array<string, string> $extra */
    private function send(string $identityId, string $template, ClientContext $client, array $extra): void
    {
        $profile = $this->identities->findProfile($identityId);
        if ($profile === null) {
            return;
        }
        $this->outbox->enqueue(new EmailAddress($profile['email']), $template, [
            'name' => $profile['full_name'],
            'device' => $client->device()->label(),
            'location' => $client->location(),
            'ip' => $client->ip,
            'time' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ] + $extra);
    }

    private function safely(string $kind, \Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->logger->error('notify.enqueue_failed', ['kind' => $kind, 'error' => $e::class]);
        }
    }
}
