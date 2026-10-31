-- Payment attempts (payments threat model PY-5, PY-6; SRS PAY-004, §5.2).
-- One row per try at paying an invoice through a gateway's hosted checkout.
-- The web tier inserts it as 'initiated' with the amount and currency copied
-- from the invoice at that moment; the payments worker claims it, asks the
-- gateway for a checkout page and moves it to 'pending' (URL stored) or
-- 'failed' (fixed reason code). Card data and gateway responses are never
-- stored (PY-19). The runtime user may update only the state columns.
CREATE TABLE payment_attempts (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    reference VARCHAR(30) CHARACTER SET ascii NOT NULL,
    account_id CHAR(32) CHARACTER SET ascii NOT NULL,
    invoice_id CHAR(32) CHARACTER SET ascii NOT NULL,
    initiated_by CHAR(32) CHARACTER SET ascii NOT NULL,
    gateway VARCHAR(16) CHARACTER SET ascii NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    checkout_url VARCHAR(512) CHARACTER SET ascii NULL,
    failure_reason VARCHAR(32) CHARACTER SET ascii NULL,
    claimed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    state_changed_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_payment_attempts_reference (reference),
    UNIQUE KEY uq_payment_attempts_idempotency (account_id, idempotency_key),
    KEY idx_payment_attempts_invoice (invoice_id, created_at),
    KEY idx_payment_attempts_state (state, created_at),
    CONSTRAINT fk_payment_attempts_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_payment_attempts_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_payment_attempts_identity FOREIGN KEY (initiated_by) REFERENCES identities (id),
    CONSTRAINT chk_payment_attempts_gateway CHECK (gateway IN ('paystack', 'flutterwave')),
    CONSTRAINT chk_payment_attempts_state CHECK (state IN ('initiated', 'pending', 'succeeded', 'failed', 'abandoned', 'unknown', 'amount_mismatch', 'refunded', 'partially_refunded')),
    CONSTRAINT chk_payment_attempts_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_payment_attempts_amount CHECK (amount_minor > 0 AND amount_minor <= 10000000000000),
    CONSTRAINT chk_payment_attempts_reference CHECK (reference REGEXP '^PXC-[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
    CONSTRAINT chk_payment_attempts_url CHECK (checkout_url IS NULL OR checkout_url LIKE 'https://%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
