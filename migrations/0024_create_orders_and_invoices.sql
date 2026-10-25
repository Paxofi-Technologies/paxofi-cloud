-- Orders and invoices (THREAT-MODEL-COMMERCE.md CD-24…CD-26; SRS CRT-005,
-- ORD-001, ORD-002, INV-001, INV-002). Lines are immutable: the runtime user
-- gets SELECT and INSERT only, and on orders and invoices it may update only
-- the status columns (column-level grants in RuntimeGrants).

-- Gap-free numbers per kind and year, allocated under a row lock inside the
-- placing transaction, so a rollback returns the number too (INV-002).
CREATE TABLE document_sequences (
    kind VARCHAR(8) CHARACTER SET ascii NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    seq_value INT UNSIGNED NOT NULL,
    PRIMARY KEY (kind, year),
    CONSTRAINT chk_document_sequences_kind CHECK (kind IN ('order', 'invoice'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE orders (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    number VARCHAR(20) CHARACTER SET ascii NOT NULL,
    account_id CHAR(32) CHARACTER SET ascii NOT NULL,
    placed_by CHAR(32) CHARACTER SET ascii NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    subtotal_minor BIGINT NOT NULL,
    tax_minor BIGINT NOT NULL,
    total_minor BIGINT NOT NULL,
    tax_country CHAR(2) CHARACTER SET ascii NOT NULL,
    tax_name VARCHAR(40) NOT NULL,
    tax_rate_bp INT UNSIGNED NOT NULL,
    billing_country CHAR(2) CHARACTER SET ascii NOT NULL,
    visitor_country CHAR(2) CHARACTER SET ascii NULL,
    flagged_for_review TINYINT(1) NOT NULL,
    terms_version VARCHAR(32) CHARACTER SET ascii NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    placed_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    status_changed_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_orders_number (number),
    UNIQUE KEY uq_orders_idempotency (account_id, idempotency_key),
    KEY idx_orders_account_placed (account_id, placed_at),
    CONSTRAINT fk_orders_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_orders_identity FOREIGN KEY (placed_by) REFERENCES identities (id),
    CONSTRAINT chk_orders_status CHECK (status IN ('pending_payment', 'paid', 'cancelled', 'expired', 'fraud_review', 'fulfilling', 'fulfilled', 'partially_fulfilled', 'failed')),
    CONSTRAINT chk_orders_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_orders_amounts CHECK (subtotal_minor > 0 AND tax_minor >= 0 AND total_minor = subtotal_minor + tax_minor AND total_minor <= 10000000000000),
    CONSTRAINT chk_orders_rate CHECK (tax_rate_bp <= 10000),
    CONSTRAINT chk_orders_number CHECK (number REGEXP '^PXO-[0-9]{4}-[0-9]{6,}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE order_lines (
    order_id CHAR(32) CHARACTER SET ascii NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    kind VARCHAR(24) CHARACTER SET ascii NOT NULL,
    domain VARCHAR(253) CHARACTER SET ascii NOT NULL,
    years TINYINT UNSIGNED NOT NULL,
    price_minor BIGINT NOT NULL,
    tax_minor BIGINT NOT NULL,
    total_minor BIGINT NOT NULL,
    renewal_price_minor BIGINT NOT NULL,
    registrar VARCHAR(32) CHARACTER SET ascii NOT NULL,
    cost_minor BIGINT NOT NULL,
    cost_currency CHAR(3) CHARACTER SET ascii NOT NULL,
    fx_set_id CHAR(32) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (order_id, line_no),
    CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT chk_order_lines_kind CHECK (kind IN ('domain_register')),
    CONSTRAINT chk_order_lines_years CHECK (years BETWEEN 1 AND 10),
    CONSTRAINT chk_order_lines_amounts CHECK (price_minor > 0 AND tax_minor >= 0 AND total_minor = price_minor + tax_minor AND renewal_price_minor > 0 AND cost_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE invoices (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    number VARCHAR(20) CHARACTER SET ascii NOT NULL,
    account_id CHAR(32) CHARACTER SET ascii NOT NULL,
    order_id CHAR(32) CHARACTER SET ascii NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    subtotal_minor BIGINT NOT NULL,
    tax_minor BIGINT NOT NULL,
    total_minor BIGINT NOT NULL,
    tax_country CHAR(2) CHARACTER SET ascii NOT NULL,
    tax_name VARCHAR(40) NOT NULL,
    tax_rate_bp INT UNSIGNED NOT NULL,
    bill_to_name VARCHAR(100) NOT NULL,
    bill_to_company VARCHAR(100) NULL,
    bill_to_line1 VARCHAR(120) NOT NULL,
    bill_to_line2 VARCHAR(120) NULL,
    bill_to_city VARCHAR(80) NOT NULL,
    bill_to_region VARCHAR(80) NOT NULL,
    bill_to_postcode VARCHAR(20) NULL,
    bill_to_country CHAR(2) CHARACTER SET ascii NOT NULL,
    bill_to_phone VARCHAR(16) CHARACTER SET ascii NOT NULL,
    bill_to_tin VARCHAR(32) NULL,
    issued_at DATETIME(6) NOT NULL,
    due_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    status_changed_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_invoices_number (number),
    UNIQUE KEY uq_invoices_order (order_id),
    KEY idx_invoices_account (account_id, issued_at),
    CONSTRAINT fk_invoices_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT chk_invoices_status CHECK (status IN ('draft', 'unpaid', 'paid', 'partially_paid', 'cancelled', 'refunded')),
    CONSTRAINT chk_invoices_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_invoices_amounts CHECK (subtotal_minor > 0 AND tax_minor >= 0 AND total_minor = subtotal_minor + tax_minor AND total_minor <= 10000000000000),
    CONSTRAINT chk_invoices_rate CHECK (tax_rate_bp <= 10000),
    CONSTRAINT chk_invoices_number CHECK (number REGEXP '^PXC-[0-9]{4}-[0-9]{6,}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE invoice_lines (
    invoice_id CHAR(32) CHARACTER SET ascii NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(300) NOT NULL,
    amount_minor BIGINT NOT NULL,
    tax_minor BIGINT NOT NULL,
    total_minor BIGINT NOT NULL,
    PRIMARY KEY (invoice_id, line_no),
    CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT chk_invoice_lines_amounts CHECK (amount_minor > 0 AND tax_minor >= 0 AND total_minor = amount_minor + tax_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
