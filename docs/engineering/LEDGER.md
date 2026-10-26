# Ledger runbook

The ledger is PaxofiCloud's own record of money (payments threat model PY-13, PY-14; SRS LED-001…LED-003). It does not depend on any payment gateway.

## Rules

- **Double entry, one currency per entry.** Every entry has at least two lines; debits equal credits. The application checks this before writing; `ledger:verify` checks it again over the whole journal.
- **Append-only.** The runtime database user can only `SELECT` and `INSERT` on `journal_entries` and `journal_lines`, and only `SELECT` on `ledger_accounts` (`RuntimeGrants`). Nothing posted is ever edited or deleted. A mistake is corrected with a **reversal** entry (`reversal:<entry id>`), and an entry can be reversed only once.
- **Exactly once.** Every entry has a unique source key (for example `invoice_issued:<invoice id>`). Posting the same source again does nothing, which is how webhook duplicates and retries stay harmless.
- **Customer balances are sums of lines.** Receivable and customer credit lines name the customer account; there is no stored balance to drift (LED-003).
- **Fixed chart of accounts** (migration 0025): `receivable`, `customer_credit`, `clearing_paystack`, `clearing_flutterwave`, `unallocated_receipts`, `revenue_domains`, `tax_payable`, `gateway_fees`. Adding an account is a migration and a code change, reviewed like any other.

## What posts today

| Event | Entry |
|---|---|
| Order placed (invoice issued) | DR receivable (customer) total · CR domain revenue subtotal · CR tax payable tax (no tax line when tax is zero) |

Payments, fees, overpayments, cancellations and refunds post as the payments work lands (threat model build order steps 4–7).

Invoices placed before the ledger existed received their issue entry once, inside migration 0025.

## Checking the ledger

```
bin/paxoficloud ledger:verify
```

- Exit 0: `ledger OK entries=… lines=… invoices=…`.
- Exit 2: one line per problem (entry or invoice ID and a fixed reason, never amounts or customer names), then `ledger BROKEN …`.

A problem means something wrote to the ledger outside the application (only the schema owner could) or a bug. Do not "fix" rows by hand: stop releases, keep the database as it is, compare with the audit log, and correct with reversing entries through a reviewed change.
