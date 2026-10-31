<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

/** Why a customer could not start a payment; the reason is a fixed code the portal maps to text. */
final class PaymentRefused extends \RuntimeException
{
    public const string KEY_INVALID = 'idempotency_key_invalid';
    public const string KEY_REUSED = 'idempotency_key_reused';
    public const string NOT_OWNER = 'not_account_owner';
    public const string INVOICE_NOT_FOUND = 'invoice_not_found';
    public const string NOT_PAYABLE = 'invoice_not_payable';
    public const string EXPIRED = 'invoice_expired';
    public const string GATEWAY_UNAVAILABLE = 'gateway_unavailable';
    public const string BELOW_MINIMUM = 'below_gateway_minimum';
    public const string TOO_MANY_OPEN = 'too_many_open_attempts';
    public const string TOO_MANY = 'too_many_attempts';
    public const string ATTEMPT_NOT_FOUND = 'payment_not_found';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
