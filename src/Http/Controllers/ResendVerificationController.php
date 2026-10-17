<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Identity\Registration\InvalidRegistration;
use PaxofiCloud\Application\Identity\Registration\ResendVerification;
use PaxofiCloud\Application\Identity\Registration\RegistrationThrottled;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;

/**
 * POST /api/v1/auth/resend-verification (SRS IAM-002). A well-formed address
 * always gets 202 with the same body as registration (T-49).
 */
final readonly class ResendVerificationController implements Controller
{
    public function __construct(private ResendVerification $resend)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_json', RequestId::of($request));
        }

        try {
            $this->resend->resend($data, JsonBody::clientIp($request));
        } catch (InvalidRegistration $e) {
            return ProblemDetails::response(422, 'validation_failed', RequestId::of($request), errors: $e->errors);
        } catch (RegistrationThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', RequestId::of($request), headers: ['retry-after' => (string) $e->retryAfterSeconds]);
        }

        return new Response(
            202,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            '{"status":"check_your_email"}',
        );
    }
}
