<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Cart\CartRefused;
use PaxofiCloud\Application\Cart\DomainCart;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;

/**
 * The signed-in customer's cart (THREAT-MODEL-COMMERCE.md CD-20):
 * GET /api/v1/cart, POST /api/v1/cart/domains {domain}, POST /api/v1/cart/remove {id}.
 * The cart is the session's account's; prices in the reply are re-quoted now
 * and are a preview until checkout locks them (DX-6).
 */
final readonly class CartController
{
    public function __construct(private DomainCart $cart, private Sessions $sessions)
    {
    }

    public function view(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }

        return self::json($this->cart->view($session->accountId));
    }

    public function addDomain(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        if ($data === null || array_keys($data) !== ['domain']) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->cart->addDomain($session->accountId, $data['domain']);
        } catch (CartRefused $e) {
            return self::refused($request, $e);
        }

        return self::json($this->cart->view($session->accountId));
    }

    public function remove(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        if ($data === null || array_keys($data) !== ['id']) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $this->cart->remove($session->accountId, $data['id']);
        } catch (CartRefused $e) {
            return self::refused($request, $e);
        }

        return self::json($this->cart->view($session->accountId));
    }

    private static function refused(HttpRequest $request, CartRefused $e): HttpResponse
    {
        return match ($e->reason) {
            CartRefused::TOO_MANY => ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter]),
            CartRefused::LINE_NOT_FOUND => ProblemDetails::response(404, $e->reason, RequestId::of($request)),
            default => ProblemDetails::response(422, $e->reason, RequestId::of($request)),
        };
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): HttpResponse
    {
        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
