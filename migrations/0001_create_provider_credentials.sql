-- Provider API credentials, encrypted at rest (SRS SEC-004/SEC-005).
-- `envelope` is XChaCha20-Poly1305 ciphertext produced by SecretCipher; the
-- plaintext token never reaches this table. `key_id` mirrors the envelope's
-- key ID so rotation can find rows still on an old key with an index scan.
CREATE TABLE provider_credentials (
    id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    environment VARCHAR(16) CHARACTER SET ascii NOT NULL,
    access_level VARCHAR(16) CHARACTER SET ascii NOT NULL,
    envelope VARCHAR(4096) CHARACTER SET ascii NOT NULL,
    key_id VARCHAR(32) CHARACTER SET ascii NOT NULL,
    review_due_on DATE NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY idx_provider_credentials_key_id (key_id),
    CONSTRAINT chk_provider_credentials_environment CHECK (environment IN ('dev', 'staging', 'prod')),
    CONSTRAINT chk_provider_credentials_access CHECK (access_level IN ('read-only', 'read-write'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
