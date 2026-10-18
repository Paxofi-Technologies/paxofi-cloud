<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Security;

use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Integration\Security\TurnstileVerification;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Threat model T-02, §6.12: server-side siteverify, hostname and action bound, fail closed. */
final class TurnstileVerificationTest extends TestCase
{
    private const string SECRET = '0x4AAAAAAAtestSecretKey';
    private const string PATH = '/turnstile/v0/siteverify';
    private const string HOST = 'cloud-staging.paxofi.com';

    public function testAValidTokenForOurHostAndFormPassesAndIsVerifiedOnTheServer(): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', self::PATH, 200, ['success' => true, 'hostname' => self::HOST, 'action' => 'register', 'error-codes' => []]);
        $logger = new RecordingLogger();

        self::assertSame(HumanCheck::Passed, $this->verifier($transport, $logger)->verify('widget-token', 'register', '198.51.100.7'));

        $sent = $transport->sent[0];
        self::assertSame(TurnstileVerification::BASE_URL, $sent['baseUrl']);
        $body = $sent['request']->json;
        self::assertIsArray($body);
        self::assertSame(self::SECRET, $body['secret']);
        self::assertSame('widget-token', $body['response']);
        self::assertSame('198.51.100.7', $body['remoteip']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['idempotency_key']);
        self::assertSame('no-store', $sent['secret'], 'The header slot carries no credential');
        self::assertSame([], $logger->records);
    }

    /** @param array<string, mixed> $reply */
    #[DataProvider('refusals')]
    public function testRefusedOrMisboundTokensFail(array $reply, string $reason): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', self::PATH, 200, $reply);
        $logger = new RecordingLogger();

        self::assertSame(HumanCheck::Failed, $this->verifier($transport, $logger)->verify('widget-token', 'sign_in', '198.51.100.7'));
        self::assertSame([['level' => 'info', 'message' => 'turnstile.failed', 'context' => ['reason' => $reason, 'action' => 'sign_in']]], $logger->records);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'rejected by Cloudflare' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 'rejected:timeout-or-duplicate'];
        yield 'odd error code is not logged' => [['success' => false, 'error-codes' => ['<script>']], 'rejected'];
        yield 'token for another site' => [['success' => true, 'hostname' => 'evil.example', 'action' => 'sign_in'], 'wrong_hostname'];
        yield 'token for another form' => [['success' => true, 'hostname' => self::HOST, 'action' => 'register'], 'wrong_action'];
        yield 'no action at all' => [['success' => true, 'hostname' => self::HOST], 'wrong_action'];
    }

    public function testAMissingTokenFailsWithoutCallingCloudflare(): void
    {
        $transport = new FakeProviderTransport();

        self::assertSame(HumanCheck::Failed, $this->verifier($transport, new RecordingLogger())->verify(null, 'register', '198.51.100.7'));
        self::assertSame([], $transport->sent);
    }

    public function testOutagesAndBadRepliesAreUnavailableSoCallersFailClosed(): void
    {
        $transport = (new FakeProviderTransport())
            ->expectFailure('POST', self::PATH)
            ->expectRaw('POST', self::PATH, 502, 'bad gateway')
            ->expectRaw('POST', self::PATH, 200, 'not json')
            ->expect('POST', self::PATH, 200, ['hostname' => self::HOST]);
        $logger = new RecordingLogger();
        $verifier = $this->verifier($transport, $logger);

        for ($i = 0; $i < 4; $i++) {
            self::assertSame(HumanCheck::Unavailable, $verifier->verify('widget-token', 'register', '198.51.100.7'));
        }
        self::assertSame(['transport', 'http_502', 'malformed', 'malformed'], array_map(static fn (array $r): mixed => $r['context']['reason'], $logger->records));
        self::assertStringNotContainsString(self::SECRET, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('widget-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testAnInvalidClientIpIsNotSent(): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', self::PATH, 200, ['success' => true, 'hostname' => self::HOST, 'action' => 'resend']);

        self::assertSame(HumanCheck::Passed, $this->verifier($transport, new RecordingLogger())->verify('widget-token', 'resend', ''));
        self::assertIsArray($transport->sent[0]['request']->json);
        self::assertArrayNotHasKey('remoteip', $transport->sent[0]['request']->json);
    }

    public function testMalformedConfigurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TurnstileVerification(new FakeProviderTransport(), "bad secret\n", self::HOST, new RecordingLogger());
    }

    private function verifier(FakeProviderTransport $transport, RecordingLogger $logger): TurnstileVerification
    {
        return new TurnstileVerification($transport, self::SECRET, self::HOST, $logger);
    }
}
