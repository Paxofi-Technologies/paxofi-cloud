<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider;

use LogicException;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PHPUnit\Framework\TestCase;

/** Threat model V-02: the secret must not leak through debugging, logging or job payloads. */
final class ProviderCredentialTest extends TestCase
{
    private const string SECRET = 'test-token-not-real-0123456789abcdef';

    public function testRevealReturnsTheSecret(): void
    {
        self::assertSame(self::SECRET, (new ProviderCredential('hetzner-prod-1', self::SECRET))->reveal());
    }

    public function testDebugOutputIsRedacted(): void
    {
        $credential = new ProviderCredential('hetzner-prod-1', self::SECRET);

        self::assertStringNotContainsString(self::SECRET, print_r($credential, true));
        ob_start();
        // The test asserts that debug output of a credential is redacted.
        var_dump($credential); // nosemgrep
        self::assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
        self::assertStringContainsString('[redacted]', print_r($credential, true));
    }

    public function testCannotBeSerialisedIntoAJobPayload(): void
    {
        $this->expectException(LogicException::class);

        serialize(new ProviderCredential('hetzner-prod-1', self::SECRET));
    }

    public function testSecretIsHiddenFromStackTraces(): void
    {
        try {
            new ProviderCredential('', self::SECRET);
            self::fail('Expected an exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }
    }

    public function testRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderCredential('hetzner-prod-1', "token\r\nX-Injected: 1");
    }
}
