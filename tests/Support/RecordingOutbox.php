<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Email\EmailAddress;

final class RecordingOutbox implements EmailOutbox
{
    /** @var list<array{to: string, template: string, parameters: array<string, string>}> */
    public array $queued = [];

    public function enqueue(EmailAddress $to, string $template, array $parameters): string
    {
        $this->queued[] = ['to' => $to->value, 'template' => $template, 'parameters' => $parameters];

        return 'outbox-' . count($this->queued);
    }
}
