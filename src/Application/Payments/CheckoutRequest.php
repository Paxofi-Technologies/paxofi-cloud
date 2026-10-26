<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * What a hosted checkout needs: our reference, the amount copied from the
 * invoice balance (PY-5), the payer's email, the page the gateway sends the
 * customer back to, and the invoice number shown on the gateway's page.
 */
final readonly class CheckoutRequest
{
    private const string EMAIL = '/^[^\s@<>"\x00-\x1F\x7F]{1,64}@[A-Za-z0-9.-]{1,253}$/D';
    private const string INVOICE = '/^PXC-[0-9]{4}-[0-9]{6}$/D';

    public function __construct(
        public PaymentReference $reference,
        public Money $amount,
        public string $email,
        public string $callbackUrl,
        public string $invoiceNumber,
    ) {
        if ($amount->minorUnits <= 0) {
            throw new \InvalidArgumentException('A payment is for a positive amount.');
        }
        if (strlen($email) > 254 || preg_match(self::EMAIL, $email) !== 1) {
            throw new \InvalidArgumentException('The payer email is not a plain address.');
        }
        $parts = parse_url($callbackUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strlen($callbackUrl) > 512 || preg_match('/[\x00-\x20\x7F]/', $callbackUrl) === 1) {
            throw new \InvalidArgumentException('The return address must be a plain https URL.');
        }
        if (preg_match(self::INVOICE, $invoiceNumber) !== 1) {
            throw new \InvalidArgumentException('Not an invoice number.');
        }
    }
}
