<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/** An active staff member and the roles they hold at this moment (loaded per request). */
final readonly class StaffPrincipal
{
    /** @param list<StaffRole> $roles */
    public function __construct(public string $staffId, public array $roles)
    {
    }

    public function can(Permission $permission): bool
    {
        foreach ($this->roles as $role) {
            if (in_array($permission, $role->permissions(), true)) {
                return true;
            }
        }

        return false;
    }
}
