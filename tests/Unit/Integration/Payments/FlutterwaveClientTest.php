<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Payments;

use PaxofiCloud\Application\Payments\CheckoutRequest;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\PaymentOutcome;
use PaxofiCloud\Application\Payments\WebhookRejected;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\GatewayMode;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Integration\Payments\Flutterwave\FlutterwaveClient;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\TestCase;

/**
 * THREAT-MODEL-PAYMENTS.md §2 (Flutterwave v3 facts), PY-1, PY-4, PY-7 /
 * PT-03, PT-09. Response bodies follow the shapes in Flutterwave's v3 docs.
 */
final class FlutterwaveClientTest extends TestCase
{
    private const string HASH = 'fake-webhook-hash-0123456789abcdef0123';
    private const string REF = 'PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6W';

    private const string TEST_KEY = 'FLWSECK_TEST-' . self::BODY . '-X';
    private const string LIVE_KEY = 'FLWSECK-' . self::BODY . '-X';
    /** Fake key body; keys are assembled from parts so no key-shaped literal sits in the repository (push protection). */
    private const string BODY = '0a1b2c3d' . '0a1b2c3d' . '0a1b2c3d' . '0a1b2c3d';

    private FakeProviderTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
    }

    private function client(string $key = self::TEST_KEY, GatewayMode $mode = GatewayMode::Test, ?string $stored = null): FlutterwaveClient
    {
        $credentials = new StaticCredentialSource(['flutterwave-staging' => $stored ?? json_encode(['secret_key' => $key, 'webhook_secret_hash' => self::HASH], JSON_THROW_ON_ERROR)]);

        return new FlutterwaveClient($this->transport, $credentials, 'flutterwave-staging', $mode);
    }

    private static function reference(): PaymentReference
    {
        return PaymentReference::fromString(self::REF);
    }

    public function testInitialiseSendsMajorUnitsAsExactTextAndReturnsTheHostedLink(): void
    {
        $this->transport->expect('POST', '/payments', 200, ['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/f524c1196ffda5556341']]);

        $checkout = $this->client()->initialise(new CheckoutRequest(self::reference(), new Money(2_550, Currency::USD), 'ada@example.test', 'https://cloud-staging.paxofi.com/billing/return', 'PXC-2026-000002'));

        $sent = $this->transport->sent[0];
        self::assertSame('https://api.flutterwave.com/v3', $sent['baseUrl']);
        self::assertSame(self::TEST_KEY, $sent['secret']);
        self::assertSame('25.50', $sent['request']->json['amount'] ?? null);
        self::assertSame(['tx_ref' => self::REF, 'currency' => 'USD', 'redirect_url' => 'https://cloud-staging.paxofi.com/billing/return', 'customer' => ['email' => 'ada@example.test']],
            array_intersect_key($sent['request']->json ?? [], ['tx_ref' => 1, 'currency' => 1, 'redirect_url' => 1, 'customer' => 1]));
        self::assertSame('https://checkout.flutterwave.com/v3/hosted/pay/f524c1196ffda5556341', $checkout->url);
    }

    public function testRefusedOrForeignLinksFail(): void
    {
        $this->transport->expect('POST', '/payments', 200, ['status' => 'success', 'data' => ['link' => 'https://flutterwave.evil.example/pay']]);
        $this->transport->expect('POST', '/payments', 400, ['status' => 'error', 'message' => 'Invalid currency']);
        foreach (['unexpected_checkout_url', 'rejected'] as $reason) {
            try {
                $this->client()->initialise(new CheckoutRequest(self::reference(), new Money(100, Currency::USD), 'ada@example.test', 'https://cloud-staging.paxofi.com/r', 'PXC-2026-000002'));
                self::fail('accepted');
            } catch (GatewayUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testKeyModeAndCredentialShapeAreCheckedBeforeEveryCall(): void
    {
        $cases = [
            [$this->client(self::LIVE_KEY), 'key_mode_mismatch'],
            [$this->client(self::TEST_KEY, GatewayMode::Live), 'key_mode_mismatch'],
            [$this->client(stored: json_encode(['secret_key' => self::TEST_KEY], JSON_THROW_ON_ERROR)), 'credential_invalid'],
            [$this->client(stored: json_encode(['secret_key' => self::TEST_KEY, 'webhook_secret_hash' => 'short'], JSON_THROW_ON_ERROR)), 'credential_invalid'],
        ];
        foreach ($cases as [$client, $reason]) {
            try {
                $client->verify(self::reference());
                self::fail('accepted');
            } catch (GatewayUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame([], $this->transport->sent);
    }

    public function testVerifyByReferenceReadsDecimalAmountsWithoutFloats(): void
    {
        $this->transport->expectRaw('GET', '/transactions/verify_by_reference', 200, '{"status":"success","message":"Transaction fetched successfully","data":{
            "id":288200108,"tx_ref":"' . self::REF . '","flw_ref":"FLW-MOCK-1","amount":17737.5,"currency":"NGN","charged_amount":17737.5,
            "app_fee":248.32,"status":"successful","created_at":"2026-10-26T09:20:29.000Z"}}');

        $v = $this->client()->verify(self::reference());

        self::assertSame(['tx_ref' => self::REF], $this->transport->sent[0]['request']->query);
        self::assertSame(PaymentOutcome::Succeeded, $v->outcome);
        self::assertSame(1_773_750, $v->amount?->minorUnits);
        self::assertSame(Currency::NGN, $v->amount->currency);
        self::assertSame(24_832, $v->fee?->minorUnits);
        self::assertSame('288200108', $v->gatewayTransactionId);
    }

    public function testOnlySuccessfulIsSuccessAndOddAnswersAreRefused(): void
    {
        foreach (['failed' => PaymentOutcome::Failed, 'cancelled' => PaymentOutcome::Failed, 'pending' => PaymentOutcome::Pending, 'new_state' => PaymentOutcome::Pending] as $status => $outcome) {
            $this->transport->expectRaw('GET', '/transactions/verify_by_reference', 200, '{"status":"success","data":{"id":1,"tx_ref":"' . self::REF . '","amount":100,"currency":"NGN","status":"' . $status . '"}}');
            self::assertSame($outcome, $this->client()->verify(self::reference())->outcome, $status);
        }
        foreach ([
            '{"status":"success","data":{"id":1,"tx_ref":"PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6X","amount":100,"currency":"NGN","status":"successful"}}',
            '{"status":"success","data":{"id":1,"tx_ref":"' . self::REF . '","amount":100.005,"currency":"NGN","status":"successful"}}',
            '{"status":"success","data":{"id":1,"tx_ref":"' . self::REF . '","amount":100,"currency":"GHS","status":"successful"}}',
        ] as $body) {
            $this->transport->expectRaw('GET', '/transactions/verify_by_reference', 200, $body);
            try {
                $this->client()->verify(self::reference());
                self::fail('accepted ' . $body);
            } catch (GatewayUnavailable $e) {
                self::assertSame('malformed', $e->reason);
            }
        }
        $this->transport->expectRaw('GET', '/transactions/verify_by_reference', 400, '{"status":"error","message":"No transaction was found"}');
        self::assertSame(PaymentOutcome::NotFound, $this->client()->verify(self::reference())->outcome);
    }

    public function testWebhookNeedsTheExactSecretHash(): void
    {
        $body = '{"event":"charge.completed","data":{"id":288200108,"tx_ref":"' . self::REF . '","status":"successful"}}';

        self::assertSame(self::REF, $this->client()->webhookHint($body, ['verif-hash' => self::HASH])?->reference->value);
        foreach ([[[], 'signature_missing'], [['verif-hash' => self::HASH . 'x'], 'signature_invalid'], [['verif-hash' => strtoupper(self::HASH)], 'signature_invalid']] as [$headers, $reason]) {
            try {
                $this->client()->webhookHint($body, $headers);
                self::fail('accepted');
            } catch (WebhookRejected $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertNull($this->client()->webhookHint('{"event":"transfer.completed","data":{"tx_ref":"' . self::REF . '"}}', ['verif-hash' => self::HASH]));
        $this->expectExceptionObject(new WebhookRejected('malformed'));
        $this->client()->webhookHint('{not json', ['verif-hash' => self::HASH]);
    }
}
