<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Middleware;

use Paxofi\Core\Contracts\HttpHandler;
use Paxofi\Core\Contracts\HttpMiddleware;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;

/**
 * Security headers for API responses (SRS API-005, threat model T-43/T-44/
 * T-48). PCF's kernel already adds X-Content-Type-Options, X-Frame-Options
 * and Referrer-Policy. The API serves JSON only, so its CSP allows nothing.
 */
final class SecurityHeadersMiddleware implements HttpMiddleware
{
    public const array HEADERS = [
        'strict-transport-security' => 'max-age=31536000; includeSubDomains; preload',
        'content-security-policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        'permissions-policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'cross-origin-opener-policy' => 'same-origin',
        'cross-origin-resource-policy' => 'same-origin',
    ];

    public function process(HttpRequest $request, HttpHandler $handler): HttpResponse
    {
        $response = $handler->handle($request);
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // Authenticated and API responses must never be cached by shared caches.
        if ($response->header('cache-control') === null) {
            $response = $response->withHeader('cache-control', 'no-store');
        }

        return $response;
    }
}
