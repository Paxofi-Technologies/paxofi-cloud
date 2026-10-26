<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

/** Why an entry was posted (PY-13). Each kind has one source-key prefix, so a source posts at most once. */
enum EntryKind: string
{
    case InvoiceIssued = 'invoice_issued';
    /** Undoes one earlier entry; its source key names that entry, so an entry is reversed at most once. */
    case Reversal = 'reversal';
    case Payment = 'payment';
    case GatewayFee = 'gateway_fee';
    case Overpayment = 'overpayment';
    case Unallocated = 'unallocated';
    case Refund = 'refund';
}
