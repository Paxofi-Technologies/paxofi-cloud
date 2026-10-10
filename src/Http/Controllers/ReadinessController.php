<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\Controller;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Http\Response;
use Paxofi\Core\Observability\HealthRegistry;
use Paxofi\Core\Observability\HealthStatus;
use PaxofiCloud\Http\RequestId;

/**
 * GET /health/ready — can this instance serve traffic (MySQL, Redis)?
 *
 * Publicly returns the overall status only. Per-check results can contain
 * exception messages (host names, driver errors), so they are logged, never
 * returned (PCF reference §13).
 */
final readonly class ReadinessController implements Controller
{
    public function __construct(private HealthRegistry $health, private Logger $logger)
    {
    }

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $results = $this->health->check();

        $status = HealthStatus::Healthy;
        foreach ($results as $result) {
            if ($result->status === HealthStatus::Unhealthy) {
                $status = HealthStatus::Unhealthy;
                break;
            }
            if ($result->status === HealthStatus::Degraded) {
                $status = HealthStatus::Degraded;
            }
        }

        if ($status !== HealthStatus::Healthy) {
            $details = [];
            foreach ($results as $name => $result) {
                $details[$name] = ['status' => $result->status->value, 'message' => $result->message];
            }
            $this->logger->warning('Readiness check not healthy', [
                'request_id' => RequestId::of($request),
                'status' => $status->value,
                'checks' => $details,
            ]);
        }

        return new Response(
            $status === HealthStatus::Unhealthy ? 503 : 200,
            ['content-type' => 'application/json'],
            json_encode(['status' => $status->value], JSON_THROW_ON_ERROR),
        );
    }
}
