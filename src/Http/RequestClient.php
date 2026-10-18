<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpRequest;
use PaxofiCloud\Application\Audit\ClientContext;

/**
 * The client behind a request (threat model §6.14, T-50). The IP is nginx's
 * REMOTE_ADDR, which `real_ip` takes from CF-Connecting-IP on the tunnel hop
 * only. Country, region and city are Cloudflare's visitor-location headers:
 * approximate, reported as-is and cleaned by ClientContext.
 */
final class RequestClient
{
    public static function of(HttpRequest $request): ClientContext
    {
        return ClientContext::from(
            JsonBody::clientIp($request),
            (string) $request->header('user-agent'),
            $request->header('cf-ipcountry'),
            $request->header('cf-region'),
            $request->header('cf-ipcity'),
            RequestId::of($request) ?? '',
        );
    }
}
