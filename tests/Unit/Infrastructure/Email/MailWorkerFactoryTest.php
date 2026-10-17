<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Email;

use Paxofi\Core\Configuration\Repository;
use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\FailoverEmailSender;
use PaxofiCloud\Infrastructure\Email\MailWorkerFactory;
use PaxofiCloud\Integration\Email\Smtp\SmtpEmailSender;
use PHPUnit\Framework\TestCase;

/** Provider switching (threat model E-1, E-13). */
final class MailWorkerFactoryTest extends TestCase
{
    private const array SMTP = ['SMTP_HOST' => 'mail.example.ng', 'SMTP_PORT' => '465', 'SMTP_USERNAME' => 'no-reply@paxofi.com', 'SMTP_PASSWORD' => 'relay-pass'];

    public function testSmtpAloneIsUsedDirectly(): void
    {
        self::assertSame(['smtp'], $this->chain($this->sender('smtp', self::SMTP)));
    }

    public function testSmtpPrimaryFailsOverToAConfiguredApiProvider(): void
    {
        $sender = $this->sender('smtp', self::SMTP + ['POSTMARK_SERVER_TOKEN' => 'pm-token']);

        self::assertInstanceOf(FailoverEmailSender::class, $sender);
        self::assertSame(['smtp', 'postmark'], $this->chain($sender));
    }

    public function testPostmarkPrimaryCanUseSmtpAsItsFallback(): void
    {
        $sender = $this->sender('postmark', self::SMTP + ['POSTMARK_SERVER_TOKEN' => 'pm-token']);

        self::assertSame(['postmark', 'smtp'], $this->chain($sender));
    }

    public function testChosenProviderWithoutCredentialsRunsOnWhatIsConfigured(): void
    {
        self::assertInstanceOf(SmtpEmailSender::class, $this->sender('postmark', self::SMTP));
    }

    public function testSmtpNeedsBothHostAndPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP_HOST plus SMTP_PASSWORD');
        $this->sender('smtp', ['SMTP_HOST' => 'mail.example.ng']);
    }

    public function testSmtpPortOutsideTheTlsPortsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender('smtp', ['SMTP_PORT' => '25'] + self::SMTP);
    }

    public function testSmtpPasswordWithSpacesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender('smtp', ['SMTP_PASSWORD' => 'has space'] + self::SMTP);
    }

    public function testBase64PasswordFromTheDeployAgentIsDecoded(): void
    {
        $env = ['SMTP_PASSWORD_B64' => base64_encode('pa$$w0rd"#\'x')] + self::SMTP;
        unset($env['SMTP_PASSWORD']);
        $failover = $this->sender('smtp', $env);
        self::assertInstanceOf(FailoverEmailSender::class, $failover);
        $smtp = (new \ReflectionProperty($failover, 'primary'))->getValue($failover);

        self::assertInstanceOf(SmtpEmailSender::class, $smtp);
        $credential = (new \ReflectionProperty($smtp, 'password'))->getValue($smtp);
        self::assertInstanceOf(\PaxofiCloud\Integration\Provider\ProviderCredential::class, $credential);
        self::assertSame('pa$$w0rd"#\'x', $credential->reveal());
    }

    public function testInvalidBase64PasswordIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP_PASSWORD_B64');
        $this->sender('smtp', ['SMTP_PASSWORD_B64' => 'not base64!!'] + self::SMTP);
    }

    public function testUnknownProviderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"postmark", "resend" or "smtp"');
        $this->sender('sendmail', self::SMTP);
    }

    /** @param array<string, string> $env */
    private function sender(string $provider, array $env): TransactionalEmailSender
    {
        $config = new Repository(['mail' => ['provider' => $provider, 'from_name' => 'PaxofiCloud', 'from_address' => 'no-reply@paxofi.com']]);

        return MailWorkerFactory::sender($config, static fn (string $name): string|false => $env[$name] ?? false);
    }

    /** @return list<string> */
    private function chain(TransactionalEmailSender $sender): array
    {
        self::assertInstanceOf(FailoverEmailSender::class, $sender);
        $names = [];
        foreach (['primary', 'secondary'] as $property) {
            $inner = (new \ReflectionProperty($sender, $property))->getValue($sender);
            if ($inner instanceof TransactionalEmailSender) {
                $names[] = $inner->name();
            }
        }

        return $names;
    }
}
