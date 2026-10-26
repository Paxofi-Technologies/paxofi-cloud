<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Payments;

/**
 * The reference we give a gateway for one payment attempt: `PXC-` and a ULID
 * (SRS §6.4, PY-5). It is unguessable (80 random bits), sorts by time, and
 * uses only characters both gateways accept. Invoice numbers have a
 * different shape (`PXC-YYYY-NNNNNN`), so the two can never be confused.
 */
final readonly class PaymentReference
{
    public const string PATTERN = '/^PXC-[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';
    private const string CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Not a payment reference.');
        }

        return new self($value);
    }

    public static function tryFrom(string $value): ?self
    {
        return preg_match(self::PATTERN, $value) === 1 ? new self($value) : null;
    }

    public static function generate(\DateTimeImmutable $now): self
    {
        $ms = intdiv((int) $now->format('Uu'), 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::CROCKFORD[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $random = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $random .= sprintf('%08b', ord($byte));
        }
        $tail = '';
        foreach (str_split($random, 5) as $chunk) {
            $tail .= self::CROCKFORD[(int) bindec($chunk)];
        }

        return new self('PXC-' . $time . $tail);
    }
}
