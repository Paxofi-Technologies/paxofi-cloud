<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Payments\InvoicePayments;
use PaxofiCloud\Application\Payments\PaymentRefused;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * Paying an invoice (THREAT-MODEL-PAYMENTS.md PY-5, PY-6, PY-10, PY-17):
 *   GET  /api/v1/payments/options?order_id=   gateways the pay screen may offer
 *   POST /api/v1/payments {order_id, gateway} with an Idempotency-Key header
 *   GET  /api/v1/payments/detail?id=           the attempt, polled until its checkout URL is ready
 *   POST /api/v1/payments/check {payment_id}   back from the gateway: check now (max once per 30 s)
 * Scoped by the session's account in SQL; another account's ID is 404. The
 * amount is never taken from the request.
 */
final readonly class PaymentsController
{
    private const string ID = '/^[a-f0-9]{32}$/';

    public function __construct(private InvoicePayments $payments, private Sessions $sessions)
    {
    }

    public function options(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $orderId = $request->query()['order_id'] ?? null;
        if (!is_string($orderId) || preg_match(self::ID, $orderId) !== 1) {
            return ProblemDetails::response(404, PaymentRefused::INVOICE_NOT_FOUND, RequestId::of($request));
        }
        try {
            return self::json(200, $this->payments->options($session->accountId, $orderId));
        } catch (PaymentRefused $e) {
            return self::refused($request, $e);
        }
    }

    public function start(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        $keys = $data === null ? [] : array_keys($data);
        sort($keys);
        if ($data === null || $keys !== ['gateway', 'order_id'] || !is_string($data['order_id']) || !is_string($data['gateway'])
            || preg_match(self::ID, $data['order_id']) !== 1 || preg_match('/^[a-z]{1,16}$/', $data['gateway']) !== 1) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $started = $this->payments->start(
                $session->identityId,
                $session->accountId,
                $data['order_id'],
                $data['gateway'],
                (string) $request->header('idempotency-key'),
                RequestClient::of($request),
            );
        } catch (PaymentRefused $e) {
            return self::refused($request, $e);
        }

        return self::json($started['replayed'] ? 200 : 201, ['payment_id' => $started['payment_id']]);
    }

    public function detail(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $id = $request->query()['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            return ProblemDetails::response(404, PaymentRefused::ATTEMPT_NOT_FOUND, RequestId::of($request));
        }
        try {
            return self::json(200, ['payment' => $this->payments->status($session->accountId, $id)]);
        } catch (PaymentRefused $e) {
            return self::refused($request, $e);
        }
    }

    /** POST /api/v1/payments/check {payment_id}: the return page asks for an early gateway check (PY-10). */
    public function check(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        if ($data === null || array_keys($data) !== ['payment_id'] || !is_string($data['payment_id']) || preg_match(self::ID, $data['payment_id']) !== 1) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            return self::json(202, ['payment' => $this->payments->checkNow($session->accountId, $data['payment_id'])]);
        } catch (PaymentRefused $e) {
            return self::refused($request, $e);
        }
    }

    private static function refused(HttpRequest $request, PaymentRefused $e): HttpResponse
    {
        $status = match ($e->reason) {
            PaymentRefused::TOO_MANY => 429,
            PaymentRefused::KEY_INVALID => 400,
            PaymentRefused::NOT_OWNER => 403,
            PaymentRefused::INVOICE_NOT_FOUND, PaymentRefused::ATTEMPT_NOT_FOUND => 404,
            PaymentRefused::KEY_REUSED, PaymentRefused::NOT_PAYABLE, PaymentRefused::EXPIRED, PaymentRefused::TOO_MANY_OPEN => 409,
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
