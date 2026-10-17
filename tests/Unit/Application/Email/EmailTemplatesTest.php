<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Email;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTemplatesTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz012345ABCDEFGH';

    public function testVerificationLinkUsesTheConfiguredBaseUrlAndCarriesTheTokenInTheFragment(): void
    {
        $message = (new EmailTemplates('https://cloud.paxofi.com/'))->render(new EmailAddress('ada@example.ng'), 'verify-email', ['name' => 'Ada', 'token' => self::TOKEN], 'outbox1');

        $link = 'https://cloud.paxofi.com/verify-email#token=' . self::TOKEN;
        self::assertStringContainsString($link, $message->textBody);
        self::assertStringContainsString('href="' . $link . '"', $message->htmlBody);
        self::assertSame('verify-email', $message->tag);
        self::assertSame('outbox1', $message->idempotencyKey);
        self::assertStringNotContainsString('?token=', $message->textBody, 'Tokens must never travel in the query string');
    }

    /** M-05: a customer-controlled name cannot inject markup or a link. */
    public function testNameIsReducedToPlainLettersAndEscaped(): void
    {
        $message = (new EmailTemplates('https://cloud.paxofi.com'))->render(
            new EmailAddress('ada@example.ng'),
            'account-exists',
            ['name' => '<script>alert(1)</script> Visit https://evil.example "now"'],
            'outbox2',
        );

        foreach ([$message->textBody, $message->htmlBody] as $body) {
            self::assertStringNotContainsString('<script', $body);
            self::assertStringNotContainsString('evil.example', $body);
            self::assertStringNotContainsString('https://evil', $body);
        }
    }

    public function testOverlongOrEmptyNamesFallBackToAGenericGreeting(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $to = new EmailAddress('ada@example.ng');

        self::assertStringStartsWith('Hi there,', $templates->render($to, 'account-exists', ['name' => str_repeat('a', 60)], 'k')->textBody);
        self::assertStringStartsWith('Hi there,', $templates->render($to, 'account-exists', ['name' => '12345'], 'k')->textBody);
        self::assertStringStartsWith("Hi Ọlá Àdé,", $templates->render($to, 'account-exists', ['name' => 'Ọlá  Àdé'], 'k')->textBody);
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'plain http' => ['http://cloud.paxofi.com'];
        yield 'credentials' => ['https://user:pass@cloud.paxofi.com'];
        yield 'query' => ['https://cloud.paxofi.com/?x=1'];
        yield 'not a url' => ['cloud.paxofi.com'];
    }

    #[DataProvider('badBaseUrls')]
    public function testBaseUrlMustBeHttps(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailTemplates($url);
    }

    public function testLocalDevelopmentMayUseHttpOnLoopback(): void
    {
        $message = (new EmailTemplates('http://127.0.0.1:8080'))->render(new EmailAddress('a@b.ng'), 'verify-email', ['token' => self::TOKEN], 'k');
        self::assertStringContainsString('http://127.0.0.1:8080/verify-email#token=', $message->textBody);
    }

    public function testUnknownTemplateAndMalformedTokenAreRefused(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $to = new EmailAddress('a@b.ng');

        try {
            $templates->render($to, 'password-reset-v0', [], 'k');
            self::fail('Unknown template rendered');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $templates->render($to, 'verify-email', ['token' => 'short"><a href=x>'], 'k');
    }
}
