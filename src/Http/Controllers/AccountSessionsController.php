<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Audit\DeviceSummary;
use PaxofiCloud\Application\Identity\Mfa\InvalidMfaInput;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Mfa\MfaThrottled;
use PaxofiCloud\Application\Identity\Mfa\MfaUnavailable;
use PaxofiCloud\Application\Identity\Session\Session;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Identity\SignIn\SignInAlerts;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * The signed-in customer's own sessions (threat model T-23, §6.21): list,
 * sign out one, sign out all others. Only the session's own identity is ever
 * read; there is no identity or account parameter. Signing devices out needs
 * the password (and a code while two-step is on) in the same request, and the
 * owner is emailed afterwards, so a stolen session alone cannot lock them out.
 */
final readonly class AccountSessionsController
{
    public function __construct(private Sessions $sessions, private MfaSettings $stepUp, private AuditTrail $audit, private SignInAlerts $alerts)
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
        if ($data === null || !is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        // The current session ends through sign-out, which also clears the cookie.
        if (hash_equals($current->publicId(), $id)) {
            return ProblemDetails::response(409, 'use_sign_out', RequestId::of($request));
        }
        unset($data['id']);
        $client = RequestClient::of($request);
        $refused = $this->stepUp($request, $current, $data, $client, 'sign_out_device');
        if ($refused !== null) {
            return $refused;
        }
        if (!$this->sessions->revoke($current->identityId, $id)) {
            return ProblemDetails::response(404, 'session_not_found', RequestId::of($request));
        }
        $this->audit->record(AuditEventType::SESSION_REVOKED, AuditEvent::SUCCESS, $client, $current->identityId, $current->accountId);
        $this->alerts->securityChanged($current->identityId, $client, 'session_revoked');

        return self::json(['status' => 'revoked']);
    }

    public function revokeOthers(HttpRequest $request): HttpResponse
    {
        $current = Authenticated::session($request, $this->sessions, write: true);
        if ($current instanceof HttpResponse) {
            return $current;
        }
        $data = JsonBody::object($request);
        if ($data === null) {
            // `{}` decodes to an empty array, which JsonBody treats as a list; accept it as "no fields".
            $data = trim($request->body()) === '{}' ? [] : null;
        }
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        $client = RequestClient::of($request);
        $refused = $this->stepUp($request, $current, $data, $client, 'sign_out_other_devices');
        if ($refused !== null) {
            return $refused;
        }
        $ended = $this->sessions->revokeOthers($current->identityId, $current);
        $this->audit->record(AuditEventType::SESSIONS_REVOKED_OTHERS, AuditEvent::SUCCESS, $client, $current->identityId, $current->accountId, ['count' => $ended]);
        if ($ended > 0) {
            $this->alerts->securityChanged($current->identityId, $client, 'other_sessions_revoked');
        }

        return self::json(['status' => 'revoked', 'count' => $ended]);
    }

    /**
     * Null when the password (and code) check passed; otherwise the refusal.
     *
     * @param array<array-key, mixed> $data
     */
    private function stepUp(HttpRequest $request, Session $current, #[\SensitiveParameter] array $data, ClientContext $client, string $action): ?HttpResponse
    {
        try {
            $this->stepUp->stepUp($current->identityId, $data, $client, $action);

            return null;
        } catch (InvalidMfaInput $e) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request), errors: $e->errors);
        } catch (MfaThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfterSeconds]);
        } catch (MfaUnavailable) {
            return ProblemDetails::response(503, 'mfa_unavailable', RequestId::of($request));
        }
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
