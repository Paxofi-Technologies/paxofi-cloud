<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Email\Smtp;

use PaxofiCloud\Integration\Email\Smtp\SmtpServer;
use PaxofiCloud\Integration\Email\Smtp\SmtpTransportFailure;
use PaxofiCloud\Integration\Email\Smtp\StreamSmtpConnector;
use PaxofiCloud\Integration\Provider\Http\HostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Threat model E-13, M-12, M-14. */
final class SmtpServerAndConnectorTest extends TestCase
{
    public function testAcceptsOnlyTheTwoTlsSubmissionPorts(): void
    {
        self::assertTrue((new SmtpServer('mail.example.ng', 465))->implicitTls());
        self::assertFalse((new SmtpServer('mail.example.ng', 587))->implicitTls());
    }

    /** @return iterable<string, array{string, int}> */
    public static function badServers(): iterable
    {
        yield 'plain port 25' => ['mail.example.ng', 25];
        yield 'other port' => ['mail.example.ng', 2525];
        yield 'internal service port' => ['mail.example.ng', 6379];
        yield 'IPv4 literal' => ['10.0.0.5', 587];
        yield 'IPv6 literal' => ['[::1]', 587];
        yield 'single label' => ['localhost', 587];
        yield 'upper case' => ['Mail.Example.NG', 587];
        yield 'injection' => ["mail.example.ng\r\nRCPT", 587];
    }

    #[DataProvider('badServers')]
    public function testRefusesUnsafeServers(string $host, int $port): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpServer($host, $port);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeResolutions(): iterable
    {
        yield 'does not resolve' => [[]];
        yield 'loopback' => [['127.0.0.1']];
        yield 'private network' => [['10.1.2.3']];
        yield 'cloud metadata' => [['169.254.169.254']];
        yield 'one public, one private' => [['8.8.8.8', '192.168.1.10']];
        yield 'IPv6 loopback' => [['::1']];
    }

    /** @param list<string> $addresses */
    #[DataProvider('unsafeResolutions')]
    public function testRefusesHostsThatResolveToNonPublicAddresses(array $addresses): void
    {
        $this->expectException(SmtpTransportFailure::class);
        $this->connector($addresses)->vettedAddress('mail.example.ng');
    }

    public function testPinsTheConnectionToAVettedPublicAddress(): void
    {
        self::assertSame('8.8.8.8', $this->connector(['8.8.8.8', '8.8.4.4'])->vettedAddress('mail.example.ng'));
    }

    public function testTlsVerifiesTheCertificateAgainstTheConfiguredHostName(): void
    {
        $options = StreamSmtpConnector::tlsOptions('mail.example.ng');

        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_peer_name']);
        self::assertFalse($options['allow_self_signed']);
        self::assertSame('mail.example.ng', $options['peer_name']);
        self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $options['crypto_method']);
    }

    /** @param list<string> $addresses */
    private function connector(array $addresses): StreamSmtpConnector
    {
        $resolver = new class ($addresses) implements HostResolver {
            /** @param list<string> $addresses */
            public function __construct(private array $addresses)
            {
            }

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };

        return new StreamSmtpConnector($resolver);
    }
}
