<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Contracts\OrderStore;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;

/**
 * The customer's orders (ORD-004; CD-26): GET /api/v1/orders?page= and
 * GET /api/v1/orders/detail?id= and GET /api/v1/orders/invoice?id=. Scoped by the session's account in SQL;
 * another account's ID is 404. Lines show the snapshot, never today's price.
 */
final readonly class OrdersController
{
    public const int PAGE_SIZE = 20;

    public function __construct(private OrderStore $orders, private Sessions $sessions)
    {
    }

    public function list(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $page = $request->query()['page'] ?? '1';
        $page = is_string($page) && preg_match('/^[1-9][0-9]{0,3}$/', $page) === 1 ? (int) $page : 1;
        $rows = $this->orders->forAccount($session->accountId, self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);

        return self::json(['orders' => array_slice($rows, 0, self::PAGE_SIZE), 'page' => $page, 'has_more' => count($rows) > self::PAGE_SIZE]);
    }

    public function detail(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $id = $request->query()['id'] ?? null;
        $order = is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $this->orders->find($session->accountId, $id) : null;
        if ($order === null) {
            return ProblemDetails::response(404, 'order_not_found', RequestId::of($request));
        }

        return self::json(['order' => $order]);
    }

    /** GET /api/v1/orders/invoice?id=<order id>: the invoice for one of the session account's orders (INV-001). */
    public function invoice(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $id = $request->query()['id'] ?? null;
        $invoice = is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $this->orders->invoiceForOrder($session->accountId, $id) : null;
        if ($invoice === null) {
            return ProblemDetails::response(404, 'invoice_not_found', RequestId::of($request));
        }

        return self::json(['invoice' => $invoice]);
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
