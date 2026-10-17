<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Email;

use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Integration\Email\PostmarkEmailSender;
use PaxofiCloud\Integration\Email\ResendEmailSender;
use PaxofiCloud\Integration\Email\Sender;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailProviderSendersTest extends TestCase
{
    public function testPostmarkSendsOnTheTransactionalStreamWithoutTracking(): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', '/email', 200, ['ErrorCode' => 0, 'MessageID' => 'pm-123']);
        $receipt = $this->postmark($transport)->send(self::message());

        self::assertSame(['postmark', 'pm-123'], [$receipt->provider, $receipt->messageId]);
        $sent = $transport->sent[0];
        self::assertSame(PostmarkEmailSender::BASE_URL, $sent['baseUrl']);
        self::assertSame('postmark-server', $sent['credentialId']);
        $json = $sent['request']->json ?? [];
        self::assertSame('PaxofiCloud <no-reply@paxofi.com>', $json['From']);
        self::assertSame('ada@example.ng', $json['To']);
        self::assertSame('outbound', $json['MessageStream']);
        self::assertFalse($json['TrackOpens']);
        self::assertSame('None', $json['TrackLinks'], 'Link tracking would rewrite one-time links through a third party');
        self::assertSame(['outbox_id' => 'outbox-1'], $json['Metadata']);
    }

    public function testPostmarkAuthenticatesWithItsServerTokenHeader(): void
    {
        self::assertSame('X-Postmark-Server-Token: secret', PostmarkEmailSender::endpoint()->authorization('secret'));
        self::assertSame('Authorization: Bearer secret', ResendEmailSender::endpoint()->authorization('secret'));
    }

    public function testResendSendsWithTheOutboxIdAsIdempotencyKey(): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', '/emails', 200, ['id' => 're-9']);
        $receipt = $this->resend($transport)->send(self::message());

        self::assertSame(['resend', 're-9'], [$receipt->provider, $receipt->messageId]);
        $request = $transport->sent[0]['request'];
        self::assertSame(['Idempotency-Key' => 'outbox-1'], $request->headers);
        self::assertSame(['ada@example.ng'], ($request->json ?? [])['to']);
    }

    /** @return iterable<string, array{int, DeliveryFailureKind}> */
    public static function statuses(): iterable
    {
        yield 'rate limited' => [429, DeliveryFailureKind::Transient];
        yield 'server error' => [503, DeliveryFailureKind::Transient];
        yield 'bad token' => [401, DeliveryFailureKind::Configuration];
        yield 'forbidden' => [403, DeliveryFailureKind::Configuration];
        yield 'invalid request' => [422, DeliveryFailureKind::Rejected];
    }

    #[DataProvider('statuses')]
    public function testFailuresAreClassifiedForRetryAndFailover(int $status, DeliveryFailureKind $kind): void
    {
        foreach (['postmark', 'resend'] as $provider) {
            $transport = (new FakeProviderTransport())->expect('POST', $provider === 'postmark' ? '/email' : '/emails', $status, ['ErrorCode' => 300, 'Message' => 'secret detail ada@example.ng']);
            try {
                ($provider === 'postmark' ? $this->postmark($transport) : $this->resend($transport))->send(self::message());
                self::fail('Expected a delivery failure');
            } catch (EmailDeliveryFailed $e) {
                self::assertSame($kind, $e->kind, $provider);
                self::assertStringNotContainsString('ada@example.ng', $e->getMessage(), 'Provider bodies must not leak into errors/logs');
            }
        }
    }

    public function testTimeoutsAreTransient(): void
    {
        $transport = (new FakeProviderTransport())->expectFailure('POST', '/email');
        try {
            $this->postmark($transport)->send(self::message());
            self::fail('Expected a delivery failure');
        } catch (EmailDeliveryFailed $e) {
            self::assertSame(DeliveryFailureKind::Transient, $e->kind);
        }
    }

    public function testSuccessWithoutAMessageIdIsNotTreatedAsSent(): void
    {
        $transport = (new FakeProviderTransport())->expect('POST', '/email', 200, ['ErrorCode' => 0]);
        $this->expectException(EmailDeliveryFailed::class);
        $this->postmark($transport)->send(self::message());
    }

    private function postmark(FakeProviderTransport $transport): PostmarkEmailSender
    {
        return new PostmarkEmailSender($transport, new ProviderCredential('postmark-server', 'pm-token'), self::from());
    }

    private function resend(FakeProviderTransport $transport): ResendEmailSender
    {
        return new ResendEmailSender($transport, new ProviderCredential('resend-sending', 're_token'), self::from());
    }

    private static function from(): Sender
    {
        return new Sender('PaxofiCloud', new EmailAddress('no-reply@paxofi.com'));
    }

    private static function message(): EmailMessage
    {
        return new EmailMessage(new EmailAddress('ada@example.ng'), 'Confirm your email address', 'text', '<p>html</p>', 'verify-email', 'outbox-1');
    }
}
