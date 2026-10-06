<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Hetzner;

use PaxofiCloud\Integration\Provider\Hetzner\HetznerErrorClassifier;
use PaxofiCloud\Integration\Provider\ProviderErrorClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS PRV-005: every Hetzner error lands in exactly one taxonomy class. */
final class HetznerErrorClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, int, ProviderErrorClass}> */
    public static function cases(): iterable
    {
        yield 'rate limited' => ['rate_limit_exceeded', 429, ProviderErrorClass::Retryable];
        yield 'locked by another action' => ['locked', 423, ProviderErrorClass::Retryable];
        yield 'server error' => ['server_error', 500, ProviderErrorClass::Retryable];
        yield 'maintenance' => ['maintenance', 503, ProviderErrorClass::Retryable];
        yield 'no capacity' => ['resource_unavailable', 412, ProviderErrorClass::ProviderCapacity];
        yield 'placement' => ['placement_error', 412, ProviderErrorClass::ProviderCapacity];
        yield 'invalid input' => ['invalid_input', 400, ProviderErrorClass::NonRetryableInput];
        yield 'not found' => ['not_found', 404, ProviderErrorClass::NonRetryableInput];
        yield 'protected' => ['protected', 423, ProviderErrorClass::NonRetryableInput];
        yield 'bad token' => ['unauthorized', 401, ProviderErrorClass::NonRetryableAccount];
        yield 'read-only token' => ['token_readonly', 403, ProviderErrorClass::NonRetryableAccount];
        yield 'project limit' => ['resource_limit_exceeded', 403, ProviderErrorClass::NonRetryableAccount];
        yield 'unknown code, 503' => ['http_503', 503, ProviderErrorClass::Retryable];
        yield 'unknown code, 403' => ['brand_new_code', 403, ProviderErrorClass::NonRetryableAccount];
        yield 'unknown code, 422' => ['brand_new_code', 422, ProviderErrorClass::NonRetryableInput];
        yield 'unknown code, 3xx' => ['http_302', 302, ProviderErrorClass::Unknown];
    }

    #[DataProvider('cases')]
    public function testClassification(string $code, int $status, ProviderErrorClass $expected): void
    {
        self::assertSame($expected, HetznerErrorClassifier::classify($code, $status));
    }

    public function testOnlyRetryableAndCapacityAreRetried(): void
    {
        self::assertTrue(ProviderErrorClass::Retryable->isRetryable());
        self::assertTrue(ProviderErrorClass::ProviderCapacity->isRetryable());
        self::assertFalse(ProviderErrorClass::NonRetryableInput->isRetryable());
        self::assertFalse(ProviderErrorClass::NonRetryableAccount->isRetryable());
        self::assertFalse(ProviderErrorClass::Unknown->isRetryable());
    }
}
