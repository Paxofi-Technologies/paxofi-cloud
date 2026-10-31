<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Payments\WebhookReceiver;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * POST /webhooks/paystack and POST /webhooks/flutterwave, on payments-web
 * only (THREAT-MODEL-PAYMENTS.md PY-7). The raw body goes to the adapter's
 * signature check untouched. Answers carry no detail a caller could probe
 * with: an empty 200, or a problem with a fixed code.
 */
final readonly class WebhookController
{
    private const array CODES = [400 => 'webhook_malformed', 401 => 'webhook_signature_invalid', 503 => 'webhook_unavailable'];

    public function __construct(private WebhookReceiver $receiver)
    {
    }

    public function paystack(HttpRequest $request): HttpResponse
    {
        return $this->handle(Gateway::Paystack, $request);
    }

    public function flutterwave(HttpRequest $request): HttpResponse
    {
        return $this->handle(Gateway::Flutterwave, $request);
    }

    private function handle(Gateway $gateway, HttpRequest $request): HttpResponse
    {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            // A repeated signature header is not trusted: only a single value counts.
            if (count($values) === 1) {
                $headers[strtolower((string) $name)] = $values[0];
            }
        }
        $status = $this->receiver->receive($gateway, $request->body(), $headers, RequestClient::of($request));
        if ($status === 200) {
            return new Response(200, ['content-type' => 'application/json', 'cache-control' => 'no-store'], '{"received":true}');
        }

        return ProblemDetails::response($status, self::CODES[$status] ?? 'webhook_malformed', RequestId::of($request));
    }
}
