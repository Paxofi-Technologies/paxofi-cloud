<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Identity\Registration\RegistrationThrottled;
use PaxofiCloud\Application\Identity\Registration\VerificationTokenInvalid;
use PaxofiCloud\Application\Identity\Registration\VerifyEmail;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestClient;
use PaxofiCloud\Http\RequestId;

/**
 * POST /api/v1/auth/verify-email {"token": "…"} (SRS IAM-002). The verify page
 * reads the token from the URL fragment and POSTs it, so link scanners that
 * only GET never consume it (T-25).
 */
final readonly class VerifyEmailController implements Controller
{
    public function __construct(private VerifyEmail $verify)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null || array_keys($data) !== ['token']) {
            return ProblemDetails::response(400, 'malformed_request', RequestId::of($request));
        }

        try {
            $this->verify->verify($data['token'], RequestClient::of($request));
        } catch (VerificationTokenInvalid) {
            return ProblemDetails::response(400, 'verification_link_invalid', RequestId::of($request), 'This link has already been used or has expired.');
        } catch (RegistrationThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfterSeconds]);
        }

        return new Response(200, ['content-type' => 'application/json', 'cache-control' => 'no-store'], '{"status":"verified"}');
    }
}
