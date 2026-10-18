<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;

final readonly class AdminRoute
{
    /** @param \Closure(HttpRequest, AdminContext): HttpResponse $handler */
    public function __construct(public string $method, public string $path, public AdminAccess $access, public \Closure $handler)
    {
        if (!in_array($method, ['GET', 'POST'], true) || preg_match('#^/admin-api/v1/[a-z0-9/_-]+$#', $path) !== 1) {
            throw new \InvalidArgumentException('Admin routes are GET or POST under /admin-api/v1/.');
        }
    }
}
