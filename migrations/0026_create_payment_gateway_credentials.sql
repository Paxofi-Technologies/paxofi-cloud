-- Payment gateway credentials (payments threat model PY-2, PY-3). Same shape as
-- provider_credentials, but encrypted under the payments key ring
-- (PAYMENTS_CREDENTIAL_KEYS), which only the payments worker holds, so no
-- other process can decrypt a gateway key even though it can read the row.
-- The runtime user may insert and update (save, re-encrypt), never delete.
CREATE TABLE payment_gateway_credentials (
    id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    environment VARCHAR(16) CHARACTER SET ascii NOT NULL,
    access_level VARCHAR(16) CHARACTER SET ascii NOT NULL,
    envelope VARCHAR(4096) CHARACTER SET ascii NOT NULL,
    key_id VARCHAR(32) CHARACTER SET ascii NOT NULL,
    review_due_on DATE NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY idx_payment_gateway_credentials_key_id (key_id),
    CONSTRAINT chk_payment_gateway_credentials_provider CHECK (provider IN ('paystack', 'flutterwave')),
    CONSTRAINT chk_payment_gateway_credentials_environment CHECK (environment IN ('dev', 'staging', 'prod')),
    CONSTRAINT chk_payment_gateway_credentials_access CHECK (access_level IN ('read-only', 'read-write'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Gateway keys enter through the same intake table, sealed to the payments intake key (PY-3).
ALTER TABLE provider_credential_intake DROP CHECK chk_credential_intake_provider;
ALTER TABLE provider_credential_intake ADD CONSTRAINT chk_credential_intake_provider CHECK (provider IN ('domainnameapi', 'namecheap', 'dynadot', 'spaceship', 'hetzner', 'vultr', 'paystack', 'flutterwave'));
