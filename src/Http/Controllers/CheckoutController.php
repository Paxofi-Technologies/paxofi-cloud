<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Checkout\Checkout;
use PaxofiCloud\Application\Checkout\CheckoutRefused;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * Checkout (THREAT-MODEL-COMMERCE.md CD-23, CD-24):
 * POST /api/v1/checkout/review (no fields: the server cart decides),
 * GET /api/v1/checkout/review?id=, and
 * POST /api/v1/checkout/orders {review_id, accept_terms, confirm_billing_country?} with an Idempotency-Key header.
 */
final readonly class CheckoutController
{
    public function __construct(private Checkout $checkout, private Sessions $sessions)
    {
    }

    public function startReview(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        // Any price, currency or line in the body is refused rather than ignored silently (CD-3).
        if ($request->body() !== '' && $request->body() !== '{}' && $data !== []) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $id = $this->checkout->startReview($session->accountId, RequestClient::of($request)->country);

            return self::json(201, $this->checkout->review($session->accountId, $id));
        } catch (CheckoutRefused $e) {
            return self::refused($request, $e);
        }
    }

    public function review(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $id = $request->query()['id'] ?? null;
        try {
            return self::json(200, $this->checkout->review($session->accountId, is_string($id) ? $id : ''));
        } catch (CheckoutRefused $e) {
            return self::refused($request, $e);
        }
    }

    public function place(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        $keys = $data === null ? [] : array_keys($data);
        sort($keys);
        if ($data === null || !in_array($keys, [['accept_terms', 'review_id'], ['accept_terms', 'confirm_billing_country', 'review_id']], true)
            || !is_string($data['review_id']) || !is_bool($data['accept_terms']) || !is_bool($data['confirm_billing_country'] ?? false)) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $placed = $this->checkout->place(
                $session->identityId,
                $session->accountId,
                $data['review_id'],
                $data['accept_terms'],
                ($data['confirm_billing_country'] ?? false) === true,
                (string) $request->header('idempotency-key'),
                RequestClient::of($request),
            );
        } catch (CheckoutRefused $e) {
            return self::refused($request, $e);
        }

        return self::json($placed['replayed'] ? 200 : 201, [
            'order_id' => $placed['order_id'],
            'order_number' => $placed['order_number'],
            'invoice_number' => $placed['invoice_number'],
            'status' => 'pending_payment',
        ]);
    }

    private static function refused(HttpRequest $request, CheckoutRefused $e): HttpResponse
    {
        $status = match ($e->reason) {
            CheckoutRefused::TOO_MANY, CheckoutRefused::BUSY => 429,
            CheckoutRefused::REVIEW_NOT_FOUND => 404,
            CheckoutRefused::NOT_OWNER => 403,
            CheckoutRefused::PRICE_CHANGED, CheckoutRefused::KEY_REUSED, CheckoutRefused::REVIEW_PENDING => 409,
            CheckoutRefused::KEY_INVALID => 400,
            default => 422,
        };
        $headers = $status === 429 ? ['retry-after' => (string) max(1, $e->retryAfter)] : [];

        return ProblemDetails::response($status, $e->reason, RequestId::of($request), headers: $headers);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new Response(
            $status,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
