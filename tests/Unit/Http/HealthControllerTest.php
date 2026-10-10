<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Http;

use Paxofi\Core\Http\Request;
use Paxofi\Core\Observability\HealthRegistry;
use Paxofi\Core\Observability\HealthResult;
use PaxofiCloud\Http\Controllers\LivenessController;
use PaxofiCloud\Http\Controllers\ReadinessController;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticHealthCheck;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testLivenessNeedsNoDependencies(): void
    {
        $response = (new LivenessController())(new Request('GET', '/health/live'));

        self::assertSame(200, $response->status());
        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function testReadyWhenAllChecksAreHealthy(): void
    {
        $logger = new RecordingLogger();
        $response = (new ReadinessController($this->registry(HealthResult::healthy(), HealthResult::healthy()), $logger))(new Request('GET', '/health/ready'));

        self::assertSame(200, $response->status());
        self::assertSame('{"status":"healthy"}', $response->body());
        self::assertSame([], $logger->records);
    }

    public function testDegradedIsStillReady(): void
    {
        $response = (new ReadinessController($this->registry(HealthResult::healthy(), HealthResult::degraded('slow')), new RecordingLogger()))(new Request('GET', '/health/ready'));

        self::assertSame(200, $response->status());
        self::assertSame('{"status":"degraded"}', $response->body());
    }

    public function testNotReadyHidesFailureDetailsButLogsThem(): void
    {
        $registry = new HealthRegistry();
        $registry->register(new StaticHealthCheck('database', null, 'SQLSTATE[HY000] [2002] db-primary.internal:3306 refused'));
        $registry->register(new StaticHealthCheck('redis', HealthResult::healthy()));
        $logger = new RecordingLogger();

        $response = (new ReadinessController($registry, $logger))(new Request('GET', '/health/ready'));

        self::assertSame(503, $response->status());
        self::assertSame('{"status":"unhealthy"}', $response->body());
        self::assertStringNotContainsString('db-primary', $response->body());
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertStringContainsString('db-primary.internal', (string) json_encode($logger->records[0]['context']));
    }

    private function registry(HealthResult $database, HealthResult $redis): HealthRegistry
    {
        $registry = new HealthRegistry();
        $registry->register(new StaticHealthCheck('database', $database));
        $registry->register(new StaticHealthCheck('redis', $redis));

        return $registry;
    }
}
