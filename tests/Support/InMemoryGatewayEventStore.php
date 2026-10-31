<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\GatewayEventStore;
use PaxofiCloud\Application\Payments\WebhookHint;

/** Gateway events in memory, keyed like the MySQL store so a replay is a no-op. */
final class InMemoryGatewayEventStore implements GatewayEventStore
{
    /** @var array<string, array{gateway: string, event: string, reference: string, payload: string}> */
    public array $events = [];
    /** @var list<string> references whose next check was brought forward */
    public array $broughtForward = [];

    public function record(WebhookHint $hint, string $payloadSha256, \DateTimeImmutable $now): bool
    {
        $key = $hint->gateway->value . '|' . hash('sha256', $hint->event . '|' . $hint->reference->value . '|' . $payloadSha256);
        if (isset($this->events[$key])) {
            return false;
        }
        $this->events[$key] = ['gateway' => $hint->gateway->value, 'event' => $hint->event, 'reference' => $hint->reference->value, 'payload' => $payloadSha256];
        $this->broughtForward[] = $hint->reference->value;

        return true;
    }
}
