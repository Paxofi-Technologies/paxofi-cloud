<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Checkout;

/** Fixed reasons only; the API maps each to a status code. */
final class CheckoutRefused extends \RuntimeException
{
    public const string TOO_MANY = 'too_many_requests';
    public const string CART_EMPTY = 'cart_empty';
    public const string BILLING_REQUIRED = 'billing_details_required';
    public const string NOT_OWNER = 'not_account_owner';
    public const string REVIEW_NOT_FOUND = 'review_not_found';
    public const string REVIEW_PENDING = 'review_pending';
    public const string REVIEW_BLOCKED = 'review_blocked';
    public const string TERMS_REQUIRED = 'terms_not_accepted';
    public const string COUNTRY_CONFIRMATION = 'billing_country_confirmation_required';
    public const string PRICE_CHANGED = 'price_changed';
    public const string KEY_INVALID = 'idempotency_key_invalid';
    public const string KEY_REUSED = 'idempotency_key_reused';
    public const string BUSY = 'checkout_busy';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
