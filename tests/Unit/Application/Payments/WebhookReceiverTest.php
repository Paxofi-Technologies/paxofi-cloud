<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\WebhookHint;
use PaxofiCloud\Application\Payments\WebhookReceiver;
use PaxofiCloud\Application\Payments\WebhookRejected;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Http\Controllers\WebhookController;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryGatewayEventStore;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\ScriptedPaymentGateways;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use Paxofi\Core\Http\Request;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PAYMENTS.md PY-7, PY-8; PT-01, PT-15, PT-18, PT-19; PAY-005. */
final class WebhookReceiverTest extends TestCase
{
    private const string BODY = '{"event":"charge.success","data":{"reference":"x","customer":{"email":"payer@example.invalid"}}}';

    private ScriptedPaymentGateways $gateways;
    private InMemoryGatewayEventStore $events;
    private ScriptedRateLimiter $limiter;
    private InMemoryAuditLog $audit;
    private RecordingLogger $logger;
    private PaymentReference $reference;

    protected function setUp(): void
    {
        $this->gateways = new ScriptedPaymentGateways();
        $this->events = new InMemoryGatewayEventStore();
        $this->limiter = new ScriptedRateLimiter();
        $this->audit = new InMemoryAuditLog();
        $this->logger = new RecordingLogger();
        $this->reference = PaymentReference::generate(new \DateTimeImmutable('2026-10-31T10:00:00Z'));
    }

    private function receiver(): WebhookReceiver
    {
        return new WebhookReceiver($this->gateways, $this->events, $this->limiter, AuditFixtures::trail($this->audit), new FixedClock(new \DateTimeImmutable('2026-10-31T10:02:00Z')), $this->logger);
    }

    private function receive(Gateway $gateway = Gateway::Paystack, string $body = self::BODY): int
    {
        return $this->receiver()->receive($gateway, $body, ['x-paystack-signature' => 'abc'], ClientContext::ip('192.0.2.7'));
    }

    private function genuine(Gateway $gateway = Gateway::Paystack): void
    {
        $this->gateways->webhookAnswer = fn (): WebhookHint => new WebhookHint($gateway, $this->reference, $gateway === Gateway::Paystack ? 'charge.success' : 'charge.completed');
    }

    public function testAGenuineEventIsRecordedAndBringsTheCheckForward(): void
    {
        $this->genuine();

        self::assertSame(200, $this->receive());

        self::assertCount(1, $this->events->events);
        self::assertSame([$this->reference->value], $this->events->broughtForward);
        $event = array_values($this->events->events)[0];
        self::assertSame(hash('sha256', self::BODY), $event['payload'], 'PT-15: only the payload hash is kept');
    }

    public function testARetryOrReplayIsAcceptedButChangesNothing(): void
    {
        $this->genuine();
        $this->receive();

        self::assertSame(200, $this->receive(), 'gateways stop retrying on 200');
        self::assertCount(1, $this->events->events);
        self::assertCount(1, $this->events->broughtForward, 'PT-19');
    }

    public function testABadSignatureIs401AndNothingIsStored(): void
    {
        $this->gateways->webhookAnswer = static fn (): \Throwable => new WebhookRejected('signature_invalid');

        self::assertSame(401, $this->receive());

        self::assertSame([], $this->events->events, 'PT-01');
        self::assertSame([AuditEventType::PAYMENT_WEBHOOK_REJECTED], $this->audit->types());
        self::assertSame(['gateway' => 'paystack', 'reason' => 'signature_invalid'], $this->audit->events[0]->details);
    }

    public function testAMissingSignatureIs401AndAnUnreadableBody400(): void
    {
        $this->gateways->webhookAnswer = static fn (): \Throwable => new WebhookRejected('signature_missing');
        self::assertSame(401, $this->receive());

        $this->gateways->webhookAnswer = static fn (): \Throwable => new WebhookRejected('malformed');
        self::assertSame(400, $this->receive());
    }

    public function testAFloodOfForgeriesIsAuditedOnceAMinute(): void
    {
        $this->gateways->webhookAnswer = static fn (): \Throwable => new WebhookRejected('signature_invalid');

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(401, $this->receive());
        }

        self::assertCount(1, $this->audit->events);
    }

    public function testAnEventWeDoNotActOnIsAcknowledged(): void
    {
        $this->gateways->webhookAnswer = static fn (): ?WebhookHint => null;

        self::assertSame(200, $this->receive());
        self::assertSame([], $this->events->events);
    }

    public function testAMissingGatewayKeyAsksTheGatewayToRetry(): void
    {
        $this->gateways->webhookAnswer = static fn (): \Throwable => new GatewayUnavailable('credential_missing');

        self::assertSame(503, $this->receive());
        self::assertSame([], $this->events->events);
    }

    public function testAnEventForTheOtherGatewayIsRefused(): void
    {
        $this->genuine(Gateway::Flutterwave);

        self::assertSame(400, $this->receive(Gateway::Paystack));
        self::assertSame([], $this->events->events);
    }

    public function testTheBodyNeverReachesTheLogs(): void
    {
        $this->genuine();
        $this->receive();
        $this->gateways->webhookAnswer = static fn (): \Throwable => new WebhookRejected('signature_invalid');
        $this->receive();

        self::assertStringNotContainsString('payer@example.invalid', json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    public function testTheControllerPassesTheRawBodyAndSingleValuedHeaders(): void
    {
        $seenBody = null;
        $seenHeaders = [];
        $this->gateways->webhookAnswer = function (string $body, array $headers) use (&$seenBody, &$seenHeaders): WebhookHint {
            $seenBody = $body;
            $seenHeaders = $headers;

            return new WebhookHint(Gateway::Flutterwave, $this->reference, 'charge.completed');
        };
        $request = new Request('POST', '/webhooks/flutterwave', ['Verif-Hash' => ['h1'], 'X-Twice' => ['a', 'b']], self::BODY);

        $response = (new WebhookController($this->receiver()))->flutterwave($request);

        self::assertSame(200, $response->status());
        self::assertSame(self::BODY, $seenBody);
        self::assertSame('h1', $seenHeaders['verif-hash'] ?? null);
        self::assertArrayNotHasKey('x-twice', $seenHeaders, 'a repeated header is not trusted');
    }
}
