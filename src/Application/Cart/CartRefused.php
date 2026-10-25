<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Cart;

/** A cart change was refused (THREAT-MODEL-COMMERCE.md CD-20). */
final class CartRefused extends \RuntimeException
{
    public const string INVALID_NAME = 'invalid_name';
    public const string NOT_FOR_SALE = 'not_for_sale';
    public const string CART_FULL = 'cart_full';
    public const string LINE_NOT_FOUND = 'line_not_found';
    public const string TOO_MANY = 'too_many_requests';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Cart change refused: ' . $reason);
    }
}
