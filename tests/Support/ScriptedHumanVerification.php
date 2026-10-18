<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Contracts\HumanVerification;

/** Answers with a fixed result and records what was asked. */
final class ScriptedHumanVerification implements HumanVerification
{
    /** @var list<array{token: ?string, action: string, ip: string}> */
    public array $calls = [];

    public function __construct(public HumanCheck $result = HumanCheck::Passed, private bool $enabled = true)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function verify(?string $token, string $action, string $clientIp): HumanCheck
    {
        $this->calls[] = ['token' => $token, 'action' => $action, 'ip' => $clientIp];

        return $this->enabled ? $this->result : HumanCheck::Passed;
    }
}
