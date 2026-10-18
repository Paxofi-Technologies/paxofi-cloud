<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use PaxofiCloud\Domain\Staff\Permission;

/** The single access rule of an admin route (§6.18): public, any signed-in staff, or one permission. */
final readonly class AdminAccess
{
    private function __construct(public bool $public, public ?Permission $permission)
    {
    }

    /** Only the passkey ceremonies, which carry their own proof (a passkey or an invitation token). */
    public static function public(): self
    {
        return new self(true, null);
    }

    public static function signedIn(): self
    {
        return new self(false, null);
    }

    public static function permission(Permission $permission): self
    {
        return new self(false, $permission);
    }
}
