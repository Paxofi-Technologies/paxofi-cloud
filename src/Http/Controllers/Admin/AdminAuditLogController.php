<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Audit\AuditLogViewer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** The staff audit log page (identity threat model §6.24): read-only. */
final readonly class AdminAuditLogController
{
    public function __construct(private AuditLogViewer $viewer)
    {
    }

    public function list(HttpRequest $request, AdminContext $context): HttpResponse
    {
        try {
            return AdminJson::ok($this->viewer->page($context->staffId(), $request->query(), RequestClient::of($request)));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (\InvalidArgumentException) {
            // The message is a fixed field name from AuditLogQuery, but the response stays generic.
            return ProblemDetails::response(422, 'invalid_filter', RequestId::of($request));
        }
    }
}
