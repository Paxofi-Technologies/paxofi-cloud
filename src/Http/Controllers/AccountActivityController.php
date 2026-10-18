<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;

/**
 * GET /api/v1/account/activity — the signed-in customer's recent security
 * events with where and on what they happened (threat model §6.14, T-52).
 * Only the session's own identity is ever read; there is no ID parameter.
 */
final readonly class AccountActivityController implements Controller
{
    public function __construct(private Sessions $sessions, private AuditTrail $audit)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }

        $events = array_map(self::entry(...), $this->audit->recentForIdentity($session->identityId));

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return array<string, mixed> */
    private static function entry(AuditEntry $entry): array
    {
        $device = $entry->device;

        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurredAt->format('Y-m-d\TH:i:s\Z'),
            'type' => $entry->type,
            'outcome' => $entry->outcome,
            'details' => (object) $entry->details,
            'ip' => $entry->ip,
            'location' => ['country' => $entry->country, 'region' => $entry->region, 'city' => $entry->city],
            'device' => $device === null ? null : [
                'browser' => $device->browser,
                'browser_version' => $device->browserVersion,
                'os' => $device->os,
                'os_version' => $device->osVersion,
                'type' => $device->deviceType,
                'label' => $device->label(),
            ],
        ];
    }
}
