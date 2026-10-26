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
use PaxofiCloud\Integration\Payments\Paystack\PaystackClient;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\StaticCredentialSource;
use PHPUnit\Framework\TestCase;

/**
 * THREAT-MODEL-PAYMENTS.md §2 (Paystack facts), PY-1, PY-4, PY-7 / PT-03,
 * PT-09. Response bodies follow the shapes in Paystack's API reference.
 */
final class PaystackClientTest extends TestCase
{
    private const string REF = 'PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6W';

    private const string TEST_KEY = 'sk_test_' . self::BODY;
    private const string LIVE_KEY = 'sk_live_' . self::BODY;
    /** Fake key body; keys are assembled from parts so no key-shaped literal sits in the repository (push protection). */
    private const string BODY = '0a1b2c3d' . '0a1b2c3d' . '0a1b2c3d' . '0a1b2c3d' . '0a1b2c3d';

    private FakeProviderTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeProviderTransport();
    }

    private function client(string $key = self::TEST_KEY, GatewayMode $mode = GatewayMode::Test, ?string $stored = null): PaystackClient
    {
        $credentials = new StaticCredentialSource(['paystack-staging' => $stored ?? json_encode(['secret_key' => $key], JSON_THROW_ON_ERROR)]);

        return new PaystackClient($this->transport, $credentials, 'paystack-staging', $mode);
    }

    private static function request(int $minor = 1_773_750, Currency $currency = Currency::NGN): CheckoutRequest
    {
        return new CheckoutRequest(PaymentReference::fromString(self::REF), new Money($minor, $currency), 'ada@example.test', 'https://cloud-staging.paxofi.com/billing/return', 'PXC-2026-000001');
    }

    public function testInitialiseSendsSubunitsAndOurReferenceAndReturnsTheHostedPage(): void
    {
        $this->transport->expect('POST', '/transaction/initialize', 200, ['status' => true, 'message' => 'Authorization URL created', 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/0peioxfhpn', 'access_code' => '0peioxfhpn', 'reference' => self::REF,
        ]]);

        $checkout = $this->client()->initialise(self::request());

        $sent = $this->transport->sent[0];
        self::assertSame('https://api.paystack.co', $sent['baseUrl']);
        self::assertSame(self::TEST_KEY, $sent['secret']);
        self::assertSame(['email' => 'ada@example.test', 'amount' => '1773750', 'currency' => 'NGN', 'reference' => self::REF,
            'callback_url' => 'https://cloud-staging.paxofi.com/billing/return', 'metadata' => '{"invoice":"PXC-2026-000001"}'], $sent['request']->json);
        self::assertSame('https://checkout.paystack.com/0peioxfhpn', $checkout->url);
        self::assertSame('Authorization: Bearer x', PaystackClient::endpoint()->authorization('x'));
    }

    public function testACheckoutUrlOnAnyOtherHostIsRefused(): void
    {
        foreach (['https://evil.example/pay', 'http://checkout.paystack.com/x', 'https://checkout.paystack.com.evil.example/x', 'https://user@checkout.paystack.com/x', 'javascript:alert(1)'] as $url) {
            $this->transport->expect('POST', '/transaction/initialize', 200, ['status' => true, 'data' => ['authorization_url' => $url, 'reference' => self::REF]]);
            try {
                $this->client()->initialise(self::request());
                self::fail('accepted ' . $url);
            } catch (GatewayUnavailable $e) {
                self::assertSame('unexpected_checkout_url', $e->reason);
            }
        }
    }

    public function testBelowTheMinimumNoCallIsMade(): void
    {
        try {
            $this->client()->initialise(self::request(199, Currency::USD));
            self::fail('accepted USD 1.99');
        } catch (GatewayUnavailable $e) {
            self::assertSame('below_minimum', $e->reason);
        }
        self::assertSame([], $this->transport->sent);
    }

    public function testALiveKeyOnStagingIsRefusedBeforeAnyCall(): void
    {
        try {
            $this->client(self::LIVE_KEY)->initialise(self::request());
            self::fail('live key used on staging');
        } catch (GatewayUnavailable $e) {
            self::assertSame('key_mode_mismatch', $e->reason);
            self::assertTrue($e->needsNewCredential());
        }
        try {
            $this->client(self::TEST_KEY, GatewayMode::Live)->verify(PaymentReference::fromString(self::REF));
            self::fail('test key used in production');
        } catch (GatewayUnavailable $e) {
            self::assertSame('key_mode_mismatch', $e->reason);
        }
        self::assertSame([], $this->transport->sent);
    }

    public function testMalformedOrMissingCredentialsAreFixedReasons(): void
    {
        foreach (['{"secret_key":"sk_test_x"}' => 'credential_invalid', '{"secret_key":"' . self::TEST_KEY . '","extra":1}' => 'credential_invalid', 'notjson' => 'credential_invalid'] as $stored => $reason) {
            try {
                $this->client(stored: $stored)->verify(PaymentReference::fromString(self::REF));
                self::fail('accepted ' . $stored);
            } catch (GatewayUnavailable $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('sk_test', $e->getMessage());
            }
        }
        $missing = new PaystackClient($this->transport, new StaticCredentialSource([]), 'paystack-staging', GatewayMode::Test);
        $this->expectExceptionObject(new GatewayUnavailable('credential_missing'));
        $missing->verify(PaymentReference::fromString(self::REF));
    }

    public function testVerifySuccessReadsAmountCurrencyIdFeeAndTime(): void
    {
        $this->transport->expectRaw('GET', '/transaction/verify/' . self::REF, 200, '{"status":true,"message":"Verification successful","data":{
            "id":4099260516,"domain":"test","status":"success","reference":"' . self::REF . '","amount":1773750,"currency":"NGN",
            "gateway_response":"Successful","paid_at":"2026-10-26T09:15:02.000Z","fees":26606}}');

        $v = $this->client()->verify(PaymentReference::fromString(self::REF));

        self::assertSame(PaymentOutcome::Succeeded, $v->outcome);
        self::assertTrue($v->amount?->equals(new Money(1_773_750, Currency::NGN)));
        self::assertSame('4099260516', $v->gatewayTransactionId);
        self::assertSame(26_606, $v->fee?->minorUnits);
        self::assertSame('2026-10-26T09:15:02+00:00', $v->paidAt?->format(DATE_ATOM));
    }

    public function testHttp200WithAFailedChargeIsNotSuccess(): void
    {
        foreach (['failed' => PaymentOutcome::Failed, 'reversed' => PaymentOutcome::Failed, 'abandoned' => PaymentOutcome::Abandoned,
            'ongoing' => PaymentOutcome::Pending, 'something_new' => PaymentOutcome::Pending] as $status => $outcome) {
            $this->transport->expect('GET', '/transaction/verify/' . self::REF, 200, ['status' => true, 'data' => [
                'id' => 1, 'domain' => 'test', 'status' => $status, 'reference' => self::REF, 'amount' => 1_773_750, 'currency' => 'NGN',
            ]]);
            self::assertSame($outcome, $this->client()->verify(PaymentReference::fromString(self::REF))->outcome, $status);
        }
    }

    public function testAnAnswerForAnotherReferenceOrTheOtherModeIsRefused(): void
    {
        $this->transport->expect('GET', '/transaction/verify/' . self::REF, 200, ['status' => true, 'data' => [
            'id' => 1, 'domain' => 'test', 'status' => 'success', 'reference' => 'PXC-01JB8Z5X3R2Q4M6N8P0S2T4V6X', 'amount' => 1, 'currency' => 'NGN',
        ]]);
        $this->transport->expect('GET', '/transaction/verify/' . self::REF, 200, ['status' => true, 'data' => [
            'id' => 1, 'domain' => 'live', 'status' => 'success', 'reference' => self::REF, 'amount' => 1, 'currency' => 'NGN',
        ]]);
        $this->transport->expect('GET', '/transaction/verify/' . self::REF, 200, ['status' => true, 'data' => [
            'id' => 1, 'domain' => 'test', 'status' => 'success', 'reference' => self::REF, 'amount' => 1.5, 'currency' => 'NGN',
        ]]);
        foreach (['malformed', 'key_mode_mismatch', 'malformed'] as $reason) {
            try {
                $this->client()->verify(PaymentReference::fromString(self::REF));
                self::fail('accepted');
            } catch (GatewayUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testHttpFailuresMapToFixedReasons(): void
    {
        $this->transport->expectRaw('GET', '/transaction/verify/' . self::REF, 400, '{"status":false,"message":"Transaction reference not found"}');
        self::assertSame(PaymentOutcome::NotFound, $this->client()->verify(PaymentReference::fromString(self::REF))->outcome);
        foreach ([401 => 'unauthorised', 429 => 'rate_limited', 502 => 'provider_error'] as $status => $reason) {
            $this->transport->expectRaw('GET', '/transaction/verify/' . self::REF, $status, '{"status":false,"message":"<secret detail>"}');
            try {
                $this->client()->verify(PaymentReference::fromString(self::REF));
                self::fail('status ' . $status);
            } catch (GatewayUnavailable $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('secret detail', $e->getMessage());
            }
        }
        $this->transport->expectFailure('GET', '/transaction/verify/' . self::REF);
        $this->expectExceptionObject(new GatewayUnavailable('transport'));
        $this->client()->verify(PaymentReference::fromString(self::REF));
    }

    public function testWebhookSignatureIsHmacSha512OfTheRawBody(): void
    {
        $body = '{"event":"charge.success","data":{"id":4099260516,"reference":"' . self::REF . '","status":"success","amount":1773750}}';
        $signature = hash_hmac('sha512', $body, self::TEST_KEY);

        $hint = $this->client()->webhookHint($body, ['x-paystack-signature' => $signature]);

        self::assertSame(self::REF, $hint?->reference->value);
        self::assertSame('charge.success', $hint->event);
        self::assertSame(self::REF, $this->client()->webhookHint($body, ['x-paystack-signature' => strtoupper($signature)])?->reference->value);
        self::assertSame([], $this->transport->sent, 'a webhook never calls the gateway by itself');
    }

    public function testForgedOrTamperedWebhooksAreRejected(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"' . self::REF . '"}}';
        $cases = [
            [$body, [], 'signature_missing'],
            [$body, ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_attacker_guess_000000000000')], 'signature_invalid'],
            [$body, ['x-paystack-signature' => hash_hmac('sha256', $body, self::TEST_KEY)], 'signature_invalid'],
            [str_replace('charge', 'charge ', $body), ['x-paystack-signature' => hash_hmac('sha512', $body, self::TEST_KEY)], 'signature_invalid'],
            [str_repeat(' ', 262_145), ['x-paystack-signature' => 'x'], 'too_large'],
        ];
        foreach ($cases as [$raw, $headers, $reason]) {
            try {
                $this->client()->webhookHint($raw, $headers);
                self::fail('accepted ' . $reason);
            } catch (WebhookRejected $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testGenuineEventsWeDoNotActOnGiveNoHint(): void
    {
        foreach ([
            '{"event":"transfer.success","data":{"reference":"' . self::REF . '"}}',
            '{"event":"charge.success","data":{"reference":"someone-elses-ref"}}',
            '{"event":"charge.success","data":{}}',
        ] as $body) {
            self::assertNull($this->client()->webhookHint($body, ['x-paystack-signature' => hash_hmac('sha512', $body, self::TEST_KEY)]), $body);
        }
    }
}
