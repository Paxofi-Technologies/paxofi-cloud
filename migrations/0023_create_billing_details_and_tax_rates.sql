-- Billing details and the tax table (THREAT-MODEL-COMMERCE.md CD-21, CD-22;
-- SRS ACC-004, PRC-003, PRC-008). One billing row per account; invoices copy
-- it at issue time. The billing currency follows the country until the first
-- invoice sets currency_locked_at, after which it never changes here.
CREATE TABLE account_billing_details (
    account_id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    company VARCHAR(100) NULL,
    address_line1 VARCHAR(120) NOT NULL,
    address_line2 VARCHAR(120) NULL,
    city VARCHAR(80) NOT NULL,
    region VARCHAR(80) NOT NULL,
    postcode VARCHAR(20) NULL,
    country CHAR(2) CHARACTER SET ascii NOT NULL,
    phone_e164 VARCHAR(16) CHARACTER SET ascii NOT NULL,
    tin VARCHAR(32) NULL,
    billing_currency CHAR(3) CHARACTER SET ascii NOT NULL,
    currency_locked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_account_billing_details_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT chk_account_billing_details_currency CHECK (billing_currency IN ('NGN', 'USD')),
    CONSTRAINT chk_account_billing_details_country CHECK (country REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_account_billing_details_phone CHECK (phone_e164 REGEXP '^[+][1-9][0-9]{6,14}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only: a change is a new row from a later effective time. ZZ is the
-- fallback for every country without its own row. Rates are public law.
CREATE TABLE tax_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    country CHAR(2) CHARACTER SET ascii NOT NULL,
    tax_name VARCHAR(40) NOT NULL,
    rate_bp INT UNSIGNED NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    created_by VARCHAR(32) CHARACTER SET ascii NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_tax_rates_country_from (country, effective_from),
    CONSTRAINT chk_tax_rates_country CHECK (country REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_tax_rates_rate CHECK (rate_bp <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Launch rows approved by the CEO (SRS PRC-008, CR-002): Nigeria VAT 7.5%,
-- USA 0% (registered in Montana, no general sales tax), everyone else 0%.
INSERT INTO tax_rates (country, tax_name, rate_bp, effective_from, created_by) VALUES
    ('NG', 'VAT', 750, '2026-01-01 00:00:00.000000', 'migration'),
    ('US', 'Sales tax', 0, '2026-01-01 00:00:00.000000', 'migration'),
    ('ZZ', 'Tax', 0, '2026-01-01 00:00:00.000000', 'migration');
