<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Audit\Countries;
use PaxofiCloud\Application\Billing\BillingAccount;
use PaxofiCloud\Application\Billing\BillingDetailsService;
use PaxofiCloud\Application\Billing\BillingRefused;
use PaxofiCloud\Application\Billing\InvalidBillingDetails;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * The session account's billing details (THREAT-MODEL-COMMERCE.md CD-21):
 * GET /api/v1/account/billing and POST /api/v1/account/billing.
 * There is no account parameter; the session decides whose details these are.
 */
final readonly class BillingDetailsController
{
    public function __construct(private BillingDetailsService $billing, private Sessions $sessions)
    {
    }

    public function view(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }

        return $this->json($this->billing->find($session->accountId));
    }

    public function save(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }
        try {
            $saved = $this->billing->save($session->identityId, $session->accountId, $data, RequestClient::of($request));
        } catch (InvalidBillingDetails $e) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request), errors: $e->errors);
        } catch (BillingRefused $e) {
            return $e->reason === BillingRefused::TOO_MANY
                ? ProblemDetails::response(429, $e->reason, RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfter])
                : ProblemDetails::response(403, $e->reason, RequestId::of($request));
        }

        return $this->json($saved);
    }

    private function json(?BillingAccount $billing): HttpResponse
    {
        $tax = $billing === null ? null : $this->billing->taxFor($billing);
        $countries = [];
        foreach (Countries::all() as $code => $name) {
            $countries[] = ['code' => $code, 'name' => $name];
        }
        $body = [
            'details' => $billing?->details->toArray(),
            'currency' => $billing?->currency->value,
            'currency_locked' => $billing?->currencyLocked() ?? false,
            'tax' => $tax === null ? null : ['name' => $tax->name, 'rate_bp' => $tax->rate->value],
            'countries' => $countries,
        ];

        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
