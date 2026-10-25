<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/** A "Sync now" request was refused (DR-12). */
final class SyncRequestRefused extends \RuntimeException
{
    public const string TOO_SOON = 'too_soon';
    /** The registrar's connection has not been entered, so the worker would never pick the request up. */
    public const string NOT_CONNECTED = 'not_connected';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Sync request refused: ' . $reason);
    }
}
