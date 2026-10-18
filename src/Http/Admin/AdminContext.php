<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use PaxofiCloud\Application\Staff\Session\StaffSession;
use PaxofiCloud\Application\Staff\StaffPrincipal;

/** What the gate established about the caller before an admin controller runs. */
final readonly class AdminContext
{
    public function __construct(public ?StaffSession $session = null, public ?StaffPrincipal $principal = null)
    {
    }

    public function staffId(): string
    {
        if ($this->principal === null) {
            throw new \LogicException('No signed-in staff member on a public admin route.');
        }

        return $this->principal->staffId;
    }
}
