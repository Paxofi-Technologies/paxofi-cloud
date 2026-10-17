<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PHPUnit\Framework\TestCase;

final class ProviderAuthAndHeadersTest extends TestCase
{
    public function testEndpointDecidesHowTheCredentialIsSent(): void
    {
        $client = new ProviderHttpClient([new ProviderEndpoint('https://api.postmarkapp.com', 'X-Postmark-Server-Token', '')], new RecordingResolver(['8.8.8.8']));
        $options = $client->curlOptions(
            new ProviderEndpoint('https://api.postmarkapp.com', 'X-Postmark-Server-Token', ''),
            ProviderRequest::post('/email', ['a' => 1], ['Idempotency-Key' => 'abc123']),
            new ProviderCredential('pm', 'tok'),
            ['8.8.8.8'],
        );
        /** @var list<string> $headers */
        $headers = $options[CURLOPT_HTTPHEADER];

        self::assertContains('X-Postmark-Server-Token: tok', $headers);
        self::assertContains('Idempotency-Key: abc123', $headers);
        self::assertSame([], array_values(array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'Authorization:'))));
    }

    public function testOnlyAllowListedRequestHeadersWithSafeValues(): void
    {
        foreach ([['Host' => 'evil'], ['Idempotency-Key' => "a\r\nX-Evil: 1"], ['Idempotency-Key' => '']] as $headers) {
            try {
                ProviderRequest::post('/emails', [], $headers);
                self::fail('Accepted an unsafe header');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAuthHeaderNameCannotInjectHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderEndpoint('https://api.resend.com', "Authorization: x\r\nHost");
    }
}
