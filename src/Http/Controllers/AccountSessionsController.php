<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\DeviceSummary;
use PaxofiCloud\Application\Identity\Session\Session;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * The signed-in customer's own sessions (threat model T-23, §6.21): list,
 * sign out one, sign out all others. Only the session's own identity is ever
 * read; there is no identity or account parameter.
 */
final readonly class AccountSessionsController
{
    public function __construct(private Sessions $sessions, private AuditTrail $audit)
    {
    }

    public function list(HttpRequest $request): HttpResponse
    {
        $current = Authenticated::session($request, $this->sessions, write: false);
        if ($current instanceof HttpResponse) {
            return $current;
        }
        $rows = array_map(
            static fn (Session $s): array => self::row($s, $s->idHash === $current->idHash),
            $this->sessions->listFor($current->identityId),
        );

        return self::json(['sessions' => $rows]);
    }

    public function revoke(HttpRequest $request): HttpResponse
    {
        $current = Authenticated::session($request, $this->sessions, write: true);
        if ($current instanceof HttpResponse) {
            return $current;
        }
        $data = JsonBody::object($request);
        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        // The current session ends through sign-out, which also clears the cookie.
        if (hash_equals($current->publicId(), $id)) {
            return ProblemDetails::response(409, 'use_sign_out', RequestId::of($request));
        }
        if (!$this->sessions->revoke($current->identityId, $id)) {
            return ProblemDetails::response(404, 'session_not_found', RequestId::of($request));
        }
        $this->audit->record(AuditEventType::SESSION_REVOKED, AuditEvent::SUCCESS, RequestClient::of($request), $current->identityId, $current->accountId);

        return self::json(['status' => 'revoked']);
    }

    public function revokeOthers(HttpRequest $request): HttpResponse
    {
        $current = Authenticated::session($request, $this->sessions, write: true);
        if ($current instanceof HttpResponse) {
            return $current;
        }
        $ended = $this->sessions->revokeOthers($current->identityId, $current);
        $this->audit->record(AuditEventType::SESSIONS_REVOKED_OTHERS, AuditEvent::SUCCESS, RequestClient::of($request), $current->identityId, $current->accountId, ['count' => $ended]);

        return self::json(['status' => 'revoked', 'count' => $ended]);
    }

    /** @return array<string, mixed> */
    private static function row(Session $session, bool $current): array
    {
        $device = DeviceSummary::fromUserAgent($session->userAgent);

        return [
            'id' => $session->publicId(),
            'current' => $current,
            'signed_in_at' => $session->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'last_seen_at' => $session->lastSeenAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'ip' => filter_var($session->clientIp, FILTER_VALIDATE_IP) !== false ? $session->clientIp : null,
            'location' => $session->location !== '' ? $session->location : null,
            'device' => ['label' => $device->label(), 'type' => $device->deviceType],
        ];
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): HttpResponse
    {
        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
