<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

/**
 * The fixed chart of accounts (payments threat model PY-13, LED-001). Codes
 * are stored in `ledger_accounts` by migration 0025; there is no editing at
 * MVP. Receivable and customer credit are kept per customer account, so their
 * lines carry the account ID and their balances are sums of lines (LED-003).
 */
enum LedgerAccount: string
{
    case Receivable = 'receivable';
    case CustomerCredit = 'customer_credit';
    case ClearingPaystack = 'clearing_paystack';
    case ClearingFlutterwave = 'clearing_flutterwave';
    case UnallocatedReceipts = 'unallocated_receipts';
    case RevenueDomains = 'revenue_domains';
    case TaxPayable = 'tax_payable';
    case GatewayFees = 'gateway_fees';

    /** Lines on these accounts name the customer account they belong to; all other lines must not. */
    public function perCustomer(): bool
    {
        return $this === self::Receivable || $this === self::CustomerCredit;
    }
}
