<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers\Admin;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use PaxofiCloud\Application\Extensions\ExtensionChangeRefused;
use PaxofiCloud\Application\Extensions\ExtensionsAdmin;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Http\Admin\AdminContext;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/** The Domain extensions page (THREAT-MODEL-DOMAIN-PRICING.md DX-2 to DX-13). */
final readonly class AdminDomainExtensionsController
{
    public function __construct(private ExtensionsAdmin $extensions)
    {
    }

    /** The page's service, shared with the catalogue settings import so an imported extension follows the same rules. */
    public function admin(): ExtensionsAdmin
    {
        return $this->extensions;
    }

    public function overview(HttpRequest $request, AdminContext $context): HttpResponse
    {
        $query = $request->query();
        $search = is_string($query['q'] ?? null) ? substr($query['q'], 0, 63) : '';
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,4}$/', $query['page']) === 1 ? (int) $query['page'] : 1;
        try {
            return AdminJson::ok($this->extensions->overview($context->staffId(), RequestClient::of($request), $search, $page, ($query['featured'] ?? null) === '1'));
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        }
    }

    public function saveExtension(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return $this->change($request, fn (array $data) => $this->extensions->saveExtension($context->staffId(), RequestClient::of($request), $data));
    }

    public function savePricing(HttpRequest $request, AdminContext $context): HttpResponse
    {
        return $this->change($request, fn (array $data) => $this->extensions->savePricing($context->staffId(), RequestClient::of($request), $data));
    }

    /** @param \Closure(array<array-key, mixed>): void $change */
    private function change(HttpRequest $request, \Closure $change): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $change($data);
        } catch (StaffPermissionDenied) {
            return ProblemDetails::response(403, 'permission_denied', RequestId::of($request));
        } catch (ExtensionChangeRefused $e) {
            return match ($e->reason) {
                ExtensionChangeRefused::THROTTLED => ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]),
                ExtensionChangeRefused::STEP_UP => ProblemDetails::response(403, $e->reason, RequestId::of($request)),
                default => ProblemDetails::response(422, $e->reason, RequestId::of($request)),
            };
        }

        return AdminJson::ok(['status' => 'saved']);
    }
}
