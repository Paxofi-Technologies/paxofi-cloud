<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Application\Registrar\RegistrarWritesDisabled;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiClient;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiEnvironment;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiOperation;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapClient;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapEnvironment;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapOperation;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\TestCase;

/**
 * DR-4 / DR-T1 coverage: with writes disabled (staging, Live key), every write
 * operation the adapter knows is refused before the credential is loaded or a
 * request is sent; and an operation's read/write class matches its HTTP verb.
 */
final class RegistrarWriteCoverageTest extends TestCase
{
    public function testEveryWriteOperationIsRefusedWithNoSideEffect(): void
    {
        $transport = new FakeProviderTransport();
        $credentials = new StaticCredentialSource(['domainnameapi-staging' => '{"reseller_id":"abc","api_key":"abcdefgh12"}']);
        $audit = new InMemoryAuditLog();
        $guard = new RegistrarWriteGuard(false, new AuditTrail($audit, new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger()));
        $client = new DomainNameApiClient($transport, $credentials, 'domainnameapi-staging', DomainNameApiEnvironment::Live, $guard);

        $writes = 0;
        foreach (DomainNameApiOperation::cases() as $operation) {
            if (!$operation->action()->isWrite()) {
                continue;
            }
            $writes++;
            [$method] = $operation->spec();
            try {
                $client->send($operation, [], in_array($method, ['GET', 'DELETE'], true) ? null : ['domainName' => 'example.com']);
                self::fail($operation->name . ' reached the network');
            } catch (RegistrarWritesDisabled) {
                self::addToAssertionCount(1);
            }
        }

        self::assertGreaterThanOrEqual(10, $writes);
        self::assertSame([], $transport->sent, 'no write request was sent');
        self::assertSame(0, $credentials->loads, 'the credential was never even loaded');
        self::assertCount($writes, $audit->events);
    }

    public function testOnlyTheKnownReadsUseNonGetVerbs(): void
    {
        foreach (DomainNameApiOperation::cases() as $operation) {
            [$method, $path, $action] = $operation->spec();
            if ($method !== 'GET' && !$action->isWrite()) {
                // The only non-GET read: availability search sends names in a POST body.
                self::assertSame(DomainNameApiOperation::BulkSearch, $operation, $path);
            }
            if ($method === 'GET') {
                self::assertFalse($action->isWrite(), $path . ' is a GET but classed as a write');
            }
        }
    }

    public function testNamecheapOperationsAreAllReadsAndAskTheGuardFirst(): void
    {
        foreach (NamecheapOperation::cases() as $operation) {
            self::assertFalse($operation->action()->isWrite(), $operation->value . ': add a write test when Namecheap writes arrive');
        }
        // A read passes the guard even with writes disabled, and only then is the credential loaded.
        $transport = (new FakeProviderTransport())->expectRaw('POST', '/xml.response', 200, '<?xml version="1.0"?><ApiResponse Status="OK"/>');
        $credentials = new StaticCredentialSource(['namecheap-staging' => '{"api_user":"a","username":"a","api_key":"abcdefgh12","client_ip":"8.8.4.4"}']);
        $guard = new RegistrarWriteGuard(false, new AuditTrail(new InMemoryAuditLog(), new FixedClock(new \DateTimeImmutable('2026-10-24T06:00:00Z')), new RecordingLogger()));
        (new NamecheapClient($transport, $credentials, 'namecheap-staging', NamecheapEnvironment::Live, $guard))->send(NamecheapOperation::GetPricing, ['ProductType' => 'DOMAIN'], 'register');

        self::assertSame(1, $credentials->loads);
    }
}
