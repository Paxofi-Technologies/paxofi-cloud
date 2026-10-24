<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Session;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\Session\StaffSession;
use PaxofiCloud\Application\Staff\Session\StaffSessionControl;
use PaxofiCloud\Application\Staff\Session\StaffSessionRefused;
use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryStaffSessionStore;
use PaxofiCloud\Tests\Support\MfaFixtures;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Staff session list, sign-out and forced sign-out (identity threat model §6.25, T-58). */
final class StaffSessionControlTest extends TestCase
{
    private StaffPasskeyFixtures $f;
    private InMemoryStaffSessionStore $store;
    private StaffSessions $sessions;
    private StaffSessionControl $control;
    private string $ada;
    private string $bob;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->ada = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $this->f->activate($this->f->lastToken());
        $this->bob = $this->f->invitations->invite($this->ada, 'bob@example.test', 'Bob Smith', StaffRole::Catalogue, AuditFixtures::client());
        $this->f->activate($this->f->lastToken());
        $this->f->audit->events = [];
        $this->f->outbox->queued = [];
        $this->store = new InMemoryStaffSessionStore();
        $this->sessions = new StaffSessions($this->store, $this->f->directory, $this->f->clock);
        $trail = AuditFixtures::trail($this->f->audit);
        $this->control = new StaffSessionControl(
            $this->sessions,
            new StaffAuthorizer($this->f->directory, $trail),
            $this->f->directory,
            new StaffSecondFactor($this->f->credentials, MfaFixtures::crypto(), $this->f->clock),
            $this->f->limiter,
            $this->f->outbox,
            $this->f->clock,
            $trail,
            $this->f->logger,
        );
    }

    private static function laptop(): ClientContext
    {
        return ClientContext::from('203.0.113.45', AuditFixtures::USER_AGENT, 'NG', 'Lagos', 'Ikeja', 'req-1');
    }

    private function signIn(string $staffId, ?ClientContext $client = null): StaffSession
    {
        $session = $this->sessions->issue($staffId, $client ?? self::laptop())->session;
        $this->f->clock->advance('+1 second');

        return $session;
    }

    private function code(string $staffId): string
    {
        $this->f->clock->advance('+31 seconds');

        return $this->f->totpCode($staffId);
    }

    public function testListsOwnSessionsNewestFirstWithDeviceAndCurrentFlag(): void
    {
        $old = $this->signIn($this->ada, ClientContext::from('198.51.100.9', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'GB', null, null, 'r'));
        $current = $this->signIn($this->ada);
        $this->signIn($this->bob);

        $list = $this->control->list($current);

        self::assertCount(2, $list, 'only the member\'s own sessions');
        self::assertSame([$current->publicId(), $old->publicId()], array_column($list, 'id'));
        self::assertSame([true, false], array_column($list, 'current'));
        self::assertSame('203.0.113.45', $list[0]['ip']);
        self::assertSame('Ikeja, Lagos, Nigeria', $list[0]['location']);
        self::assertStringContainsString('Chrome', $list[0]['device']);
        self::assertStringContainsString('iOS', $list[1]['device']);
        self::assertNotContains($current->idHash, array_column($list, 'id'), 'the store key never leaves the server');
    }

    public function testRevokingOneOwnSessionNeedsAFreshCodeAndEmailsTheOwner(): void
    {
        $other = $this->signIn($this->ada);
        $current = $this->signIn($this->ada);

        $this->expectRefused(StaffSessionRefused::STEP_UP, fn () => $this->control->revoke($current, $other->publicId(), '000000', self::laptop()));
        self::assertNotNull($this->store->find($other->idHash));

        $this->control->revoke($current, $other->publicId(), $this->code($this->ada), self::laptop());

        self::assertNull($this->store->find($other->idHash));
        self::assertNotNull($this->store->find($current->idHash));
        self::assertSame(AuditEventType::STAFF_SESSION_REVOKED, end($this->f->audit->events)->type ?? null);
        $mail = end($this->f->outbox->queued);
        self::assertNotFalse($mail);
        self::assertSame(['ada@example.test', EmailTemplates::STAFF_SECURITY_CHANGE, 'session_revoked'], [$mail['to'], $mail['template'], $mail['parameters']['change']]);
        self::assertSame('203.0.113.45', $mail['parameters']['ip']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $mail['parameters']['time']);
    }

    public function testAnotherMembersSessionIdIsNotFound(): void
    {
        $bobs = $this->signIn($this->bob);
        $current = $this->signIn($this->ada);

        $this->expectRefused(StaffSessionRefused::NOT_FOUND, fn () => $this->control->revoke($current, $bobs->publicId(), $this->code($this->ada), self::laptop()));
        self::assertNotNull($this->store->find($bobs->idHash));
    }

    public function testRevokeOthersKeepsTheCurrentSession(): void
    {
        $this->signIn($this->ada);
        $this->signIn($this->ada);
        $current = $this->signIn($this->ada);
        $bobs = $this->signIn($this->bob);

        self::assertSame(2, $this->control->revokeOthers($current, $this->code($this->ada), self::laptop()));

        self::assertSame([$current->idHash], array_map(static fn (StaffSession $s): string => $s->idHash, $this->store->forStaff($this->ada)));
        self::assertNotNull($this->store->find($bobs->idHash), 'other members untouched');
        self::assertSame(['subject' => $this->ada, 'ended' => 2], end($this->f->audit->events)->details ?? null);
        self::assertSame('other_sessions_revoked', end($this->f->outbox->queued)['parameters']['change'] ?? null);
    }

    public function testAManagerEndsEveryOtherMembersSessionAndTheyAreEmailed(): void
    {
        $this->signIn($this->bob);
        $this->signIn($this->bob);
        $adas = $this->signIn($this->ada);

        self::assertSame(2, $this->control->endAllFor($this->ada, $this->bob, $this->code($this->ada), self::laptop()));

        self::assertSame([], $this->store->forStaff($this->bob));
        self::assertNotNull($this->store->find($adas->idHash));
        self::assertSame('active', $this->f->directory->status($this->bob), 'status unchanged');
        self::assertSame([StaffRole::Catalogue], $this->f->directory->activeRoles($this->bob), 'roles unchanged');
        $event = end($this->f->audit->events);
        self::assertNotFalse($event);
        self::assertSame([AuditEventType::STAFF_SESSIONS_ENDED, ['subject' => $this->ada, 'target' => $this->bob, 'ended' => 2]], [$event->type, $event->details]);
        $mail = end($this->f->outbox->queued);
        self::assertNotFalse($mail);
        self::assertSame(['bob@example.test', 'sessions_ended_by_manager'], [$mail['to'], $mail['parameters']['change']]);
    }

    public function testOnlyStaffManagersMayEndOthersSessions(): void
    {
        $adas = $this->signIn($this->ada);

        try {
            $this->control->endAllFor($this->bob, $this->ada, $this->code($this->bob), self::laptop());
            self::fail('Catalogue may not end sessions');
        } catch (StaffPermissionDenied) {
        }
        self::assertNotNull($this->store->find($adas->idHash));
        self::assertSame(AuditEventType::STAFF_PERMISSION_DENIED, end($this->f->audit->events)->type ?? null);
    }

    public function testAManagerCannotTargetThemselvesOrAnUnknownMember(): void
    {
        $this->expectRefused(StaffSessionRefused::OWN_ACCOUNT, fn () => $this->control->endAllFor($this->ada, $this->ada, $this->code($this->ada), self::laptop()));
        $this->expectRefused(StaffSessionRefused::NOT_FOUND, fn () => $this->control->endAllFor($this->ada, str_repeat('f', 32), $this->code($this->ada), self::laptop()));
        $this->expectRefused(StaffSessionRefused::NOT_FOUND, fn () => $this->control->endAllFor($this->ada, '../x', $this->code($this->ada), self::laptop()));
    }

    public function testAForcedSignOutNeedsTheActingManagersFreshCode(): void
    {
        $bobs = $this->signIn($this->bob);

        $this->expectRefused(StaffSessionRefused::STEP_UP, fn () => $this->control->endAllFor($this->ada, $this->bob, $this->code($this->bob), self::laptop()));
        self::assertNotNull($this->store->find($bobs->idHash), 'the target\'s own code is not the manager\'s');
        self::assertSame($this->bob, end($this->f->audit->events)->details['target'] ?? null);
    }

    public function testCodeAttemptsAreLimitedPerActingMember(): void
    {
        $current = $this->signIn($this->ada);
        for ($i = 0; $i < StaffSessionControl::ATTEMPTS_PER_WINDOW; $i++) {
            try {
                $this->control->revokeOthers($current, '000000', self::laptop());
            } catch (StaffSessionRefused) {
            }
        }

        $this->expectRefused(StaffSessionRefused::THROTTLED, fn () => $this->control->revokeOthers($current, $this->code($this->ada), self::laptop()));
    }

    public function testAFailedEmailNeverUndoesOrBlocksTheSignOut(): void
    {
        $bobs = $this->signIn($this->bob);
        $this->f->directory->add(str_repeat('9', 32), 'active');
        $failing = new class implements \PaxofiCloud\Application\Contracts\EmailOutbox {
            public function enqueue(\PaxofiCloud\Application\Email\EmailAddress $to, string $template, array $parameters): string
            {
                throw new \RuntimeException('outbox down');
            }
        };
        $trail = AuditFixtures::trail($this->f->audit);
        $control = new StaffSessionControl($this->sessions, new StaffAuthorizer($this->f->directory, $trail), $this->f->directory, new StaffSecondFactor($this->f->credentials, MfaFixtures::crypto(), $this->f->clock), $this->f->limiter, $failing, $this->f->clock, $trail, $this->f->logger);

        self::assertSame(1, $control->endAllFor($this->ada, $this->bob, $this->code($this->ada), self::laptop()));
        self::assertNull($this->store->find($bobs->idHash));
        self::assertStringContainsString('staff_session_alert.failed', serialize($this->f->logger->records));
    }

    private function expectRefused(string $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected refusal: ' . $reason);
        } catch (StaffSessionRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = end($this->f->audit->events);
        self::assertNotFalse($last);
        self::assertSame(AuditEventType::STAFF_SESSION_CHANGE_REFUSED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }
}
