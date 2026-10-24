<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Dynadot;

use PaxofiCloud\Integration\Registrar\Dynadot\DynadotSignature;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md DY-3 / DY-T2. */
final class DynadotSignatureTest extends TestCase
{
    public function testMatchesTheDocumentedConstructionComputedIndependently(): void
    {
        // Computed outside PHP: printf 'key\n/restful/v2/domains/get_tld_price?currency=usd\nid-1\n' | openssl dgst -sha256 -hmac secret -binary | base64
        self::assertSame('smVb44zCLijEIvY0HHYd+LzAhS7HaZpavTyca3E2mbs=', DynadotSignature::compute('key', 'secret', '/restful/v2/domains/get_tld_price?currency=usd', 'id-1'));
    }

    public function testEveryPartChangesTheSignatureAndTheSecretNeverAppearsInIt(): void
    {
        $base = DynadotSignature::compute('key', 'secret', '/p?a=1', 'id', '');
        self::assertNotSame($base, DynadotSignature::compute('key2', 'secret', '/p?a=1', 'id', ''));
        self::assertNotSame($base, DynadotSignature::compute('key', 'secret2', '/p?a=1', 'id', ''));
        self::assertNotSame($base, DynadotSignature::compute('key', 'secret', '/p?a=2', 'id', ''));
        self::assertNotSame($base, DynadotSignature::compute('key', 'secret', '/p?a=1', 'id2', ''));
        self::assertNotSame($base, DynadotSignature::compute('key', 'secret', '/p?a=1', 'id', '{}'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{43}=$/', $base);
        self::assertStringNotContainsString('secret', base64_decode($base, true) ?: '');
    }

    public function testRequestIdsAreRandomVersion4Uuids(): void
    {
        $a = DynadotSignature::requestId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        self::assertNotSame($a, DynadotSignature::requestId());
    }
}
