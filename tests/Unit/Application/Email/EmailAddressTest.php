<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Email;

use PaxofiCloud\Application\Email\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testDomainIsLowerCasedAndHashIsCaseInsensitive(): void
    {
        $address = new EmailAddress(' Ada.Obi@Example.NG ');

        self::assertSame('Ada.Obi@example.ng', $address->value);
        self::assertSame('example.ng', $address->domain());
        self::assertSame((new EmailAddress('ada.obi@example.ng'))->hash(), $address->hash());
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'header injection' => ["a@b.ng\r\nBcc: x@y.ng"];
        yield 'two recipients' => ['a@b.ng,c@d.ng'];
        yield 'display name' => ['Ada <a@b.ng>'];
        yield 'no domain' => ['ada@'];
        yield 'too long' => [str_repeat('a', 250) . '@b.ng'];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingButOneAddress(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress($value);
    }
}
