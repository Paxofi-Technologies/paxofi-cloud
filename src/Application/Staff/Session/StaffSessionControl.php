<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Audit\DeviceSummary;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * Staff session list, sign-out and forced sign-out (identity threat model
 * §6.25, T-58). Every change needs a fresh authenticator code of the acting
 * member, is limited to 5 attempts per 15 minutes, is audited and is emailed
 * to the member whose sessions ended. Ending sessions never changes a
 * member's status or roles.
 */
final readonly class StaffSessionControl
{
    public const int ATTEMPTS_PER_WINDOW = 5;
    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private StaffSessions $sessions,
        private StaffAuthorizer $authorizer,
        private StaffDirectory $directory,
        private ?StaffSecondFactor $secondFactor,
        private RateLimiter $limiter,
        private EmailOutbox $outbox,
        private Clock $clock,
        private AuditTrail $audit,
        private Logger $logger,
    ) {
    }

    /**
     * The member's own live sessions, most recently used first.
     *
     * @return list<array{id: string, current: bool, device: string, location: ?string, ip: ?string, signed_in_at: string, last_active_at: string}>
     */
    public function list(StaffSession $current): array
    {
        return array_map(static fn (StaffSession $s): array => [
            'id' => $s->publicId(),
            'current' => $s->idHash === $current->idHash,
            'device' => $s->userAgent === '' ? 'Unknown device' : DeviceSummary::fromUserAgent($s->userAgent)->label(),
            'location' => $s->location === '' ? null : $s->location,
            'ip' => $s->clientIp === '' ? null : $s->clientIp,
            'signed_in_at' => $s->createdAt->format(\DATE_ATOM),
            'last_active_at' => $s->lastSeenAt->format(\DATE_ATOM),
        ], $this->sessions->listFor($current->staffId));
    }

    /** @throws StaffSessionRefused */
    public function revoke(StaffSession $current, string $publicId, #[\SensitiveParameter] string $code, ClientContext $client): void
    {
        $this->stepUp($current->staffId, $code, $client, 'revoke', null);
        if (!$this->sessions->revoke($current->staffId, $publicId)) {
            $this->refuse(StaffSessionRefused::NOT_FOUND, $client, $current->staffId, 'revoke', null);
        }
        $this->audit->record(AuditEventType::STAFF_SESSION_REVOKED, AuditEvent::SUCCESS, $client, details: ['subject' => $current->staffId, 'current' => $publicId === $current->publicId()]);
        $this->notify($current->staffId, 'session_revoked', $client);
    }

    /** @throws StaffSessionRefused */
    public function revokeOthers(StaffSession $current, #[\SensitiveParameter] string $code, ClientContext $client): int
    {
        $this->stepUp($current->staffId, $code, $client, 'revoke_others', null);
        $ended = $this->sessions->revokeAllExcept($current->staffId, $current);
        $this->audit->record(AuditEventType::STAFF_SESSIONS_REVOKED_OTHERS, AuditEvent::SUCCESS, $client, details: ['subject' => $current->staffId, 'ended' => $ended]);
        if ($ended > 0) {
            $this->notify($current->staffId, 'other_sessions_revoked', $client);
        }

        return $ended;
    }

    /**
     * T-58: a staff manager signs another member out on every device.
     *
     * @throws StaffSessionRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function endAllFor(string $actorId, string $targetId, #[\SensitiveParameter] string $code, ClientContext $client): int
    {
        $this->authorizer->authorize($actorId, Permission::StaffManage, $client);
        if (hash_equals($actorId, $targetId)) {
            $this->refuse(StaffSessionRefused::OWN_ACCOUNT, $client, $actorId, 'end_all', $targetId);
        }
        if (preg_match('/^[0-9a-f]{32}$/', $targetId) !== 1 || $this->directory->status($targetId) === null) {
            $this->refuse(StaffSessionRefused::NOT_FOUND, $client, $actorId, 'end_all', null);
        }
        $this->stepUp($actorId, $code, $client, 'end_all', $targetId);
        $ended = $this->sessions->revokeAllExcept($targetId, null);
        $this->audit->record(AuditEventType::STAFF_SESSIONS_ENDED, AuditEvent::SUCCESS, $client, details: ['subject' => $actorId, 'target' => $targetId, 'ended' => $ended]);
        $this->notify($targetId, 'sessions_ended_by_manager', $client);

        return $ended;
    }

    /** @throws StaffSessionRefused */
    private function stepUp(string $staffId, #[\SensitiveParameter] string $code, ClientContext $client, string $action, ?string $target): void
    {
        $retry = $this->limiter->hit('staff:session_change:' . $staffId, self::ATTEMPTS_PER_WINDOW, self::WINDOW_SECONDS);
        if ($retry > 0) {
            $this->refuse(StaffSessionRefused::THROTTLED, $client, $staffId, $action, $target, $retry);
        }
        if ($this->secondFactor === null || preg_match('/^\d{6}$/', $code) !== 1 || !$this->secondFactor->verifyCode($staffId, $code)) {
            $this->refuse(StaffSessionRefused::STEP_UP, $client, $staffId, $action, $target);
        }
    }

    /** @return never */
    private function refuse(string $reason, ClientContext $client, string $staffId, string $action, ?string $target, int $retryAfter = 0): never
    {
        $details = ['subject' => $staffId, 'action' => $action, 'reason' => $reason];
        if ($target !== null) {
            $details['target'] = $target;
        }
        $this->audit->record(AuditEventType::STAFF_SESSION_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: $details);

        throw new StaffSessionRefused($reason, $retryAfter);
    }

    /** Best effort: the change has happened; a failed email is logged, never thrown. */
    private function notify(string $staffId, string $change, ClientContext $client): void
    {
        try {
            foreach ($this->directory->members() as $member) {
                if ($member['id'] === $staffId) {
                    $this->outbox->enqueue(new EmailAddress($member['email']), EmailTemplates::STAFF_SECURITY_CHANGE, [
                        'name' => $member['full_name'],
                        'change' => $change,
                        'device' => $client->device()->label(),
                        'location' => $client->location(),
                        'ip' => $client->ip,
                        'time' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    ]);

                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('staff_session_alert.failed', ['change' => $change, 'error' => $e::class]);
        }
    }
}
