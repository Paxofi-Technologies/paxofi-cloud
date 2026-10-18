<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use PaxofiCloud\Domain\Staff\Permission;

/** The staff member does not hold the permission now; the API answers 403 (ACC-005). */
final class StaffPermissionDenied extends \RuntimeException
{
    public function __construct(public readonly Permission $permission)
    {
        parent::__construct('Staff permission denied.');
    }
}
