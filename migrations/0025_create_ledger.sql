-- Double-entry ledger (payments threat model PY-13, PY-14; SRS LED-001…LED-003).
-- Append-only: the runtime user gets SELECT and INSERT on entries and lines,
-- SELECT only on the chart of accounts (RuntimeGrants). Balance per entry is
-- checked in code before insert and by `ledger:verify` over all entries.

CREATE TABLE ledger_accounts (
    code VARCHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    normal_side VARCHAR(6) CHARACTER SET ascii NOT NULL,
    per_customer TINYINT(1) NOT NULL,
    CONSTRAINT chk_ledger_accounts_side CHECK (normal_side IN ('debit', 'credit'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO ledger_accounts (code, name, normal_side, per_customer) VALUES
    ('receivable', 'Accounts receivable', 'debit', 1),
    ('customer_credit', 'Customer credit', 'credit', 1),
    ('clearing_paystack', 'Paystack clearing', 'debit', 0),
    ('clearing_flutterwave', 'Flutterwave clearing', 'debit', 0),
    ('unallocated_receipts', 'Unallocated receipts', 'credit', 0),
    ('revenue_domains', 'Domain revenue', 'credit', 0),
    ('tax_payable', 'Tax payable', 'credit', 0),
    ('gateway_fees', 'Payment gateway fees', 'debit', 0);

CREATE TABLE journal_entries (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    source_key VARCHAR(128) CHARACTER SET ascii NOT NULL,
    kind VARCHAR(24) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    posted_at DATETIME(6) NOT NULL,
    reverses_entry_id CHAR(32) CHARACTER SET ascii NULL,
    UNIQUE KEY uq_journal_entries_source (source_key),
    KEY idx_journal_entries_posted (posted_at),
    CONSTRAINT fk_journal_entries_reverses FOREIGN KEY (reverses_entry_id) REFERENCES journal_entries (id),
    CONSTRAINT chk_journal_entries_kind CHECK (kind IN ('invoice_issued', 'reversal', 'payment', 'gateway_fee', 'overpayment', 'unallocated', 'refund')),
    CONSTRAINT chk_journal_entries_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_journal_entries_source CHECK (source_key LIKE CONCAT(kind, ':%')),
    CONSTRAINT chk_journal_entries_reversal CHECK ((kind = 'reversal' AND reverses_entry_id IS NOT NULL) OR (kind <> 'reversal' AND reverses_entry_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE journal_lines (
    entry_id CHAR(32) CHARACTER SET ascii NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    account_code VARCHAR(32) CHARACTER SET ascii NOT NULL,
    customer_account_id CHAR(32) CHARACTER SET ascii NULL,
    debit_minor BIGINT NOT NULL,
    credit_minor BIGINT NOT NULL,
    PRIMARY KEY (entry_id, line_no),
    KEY idx_journal_lines_customer (customer_account_id, account_code),
    CONSTRAINT fk_journal_lines_entry FOREIGN KEY (entry_id) REFERENCES journal_entries (id),
    CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_code) REFERENCES ledger_accounts (code),
    CONSTRAINT fk_journal_lines_customer FOREIGN KEY (customer_account_id) REFERENCES accounts (id),
    CONSTRAINT chk_journal_lines_one_side CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0)),
    CONSTRAINT chk_journal_lines_bound CHECK (debit_minor <= 10000000000000 AND credit_minor <= 10000000000000),
    CONSTRAINT chk_journal_lines_customer CHECK (
        (account_code IN ('receivable', 'customer_credit') AND customer_account_id IS NOT NULL)
        OR (account_code NOT IN ('receivable', 'customer_credit') AND customer_account_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- PY-14: invoices issued before the ledger existed get their issue entry here,
-- once, with the same postings and source key the application uses.
INSERT INTO journal_entries (id, source_key, kind, currency, posted_at)
    SELECT LEFT(SHA2(CONCAT('ledger-backfill:', i.id), 256), 32), CONCAT('invoice_issued:', i.id), 'invoice_issued', i.currency, i.issued_at
    FROM invoices i;

INSERT INTO journal_lines (entry_id, line_no, account_code, customer_account_id, debit_minor, credit_minor)
    SELECT LEFT(SHA2(CONCAT('ledger-backfill:', i.id), 256), 32), 1, 'receivable', i.account_id, i.total_minor, 0 FROM invoices i
    UNION ALL
    SELECT LEFT(SHA2(CONCAT('ledger-backfill:', i.id), 256), 32), 2, 'revenue_domains', NULL, 0, i.subtotal_minor FROM invoices i
    UNION ALL
    SELECT LEFT(SHA2(CONCAT('ledger-backfill:', i.id), 256), 32), 3, 'tax_payable', NULL, 0, i.tax_minor FROM invoices i WHERE i.tax_minor > 0;
