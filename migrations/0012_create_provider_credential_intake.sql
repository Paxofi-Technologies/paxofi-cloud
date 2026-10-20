-- Provider credentials entered in the admin console, sealed to the provider
-- worker (THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-1, PC-5). The web tier can
-- seal but never open; the worker stores the secret under the KEK in
-- provider_credentials and deletes the row. One row per credential ID: a
-- newer submission replaces a pending one. The runtime user may SELECT,
-- INSERT and DELETE, never UPDATE.
CREATE TABLE provider_credential_intake (
    credential_id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    sealed VARCHAR(8192) CHARACTER SET ascii NOT NULL,
    submitted_by CHAR(32) CHARACTER SET ascii NOT NULL,
    submitted_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_credential_intake_id CHECK (REGEXP_LIKE(credential_id, '^[a-z0-9][a-z0-9-]{0,63}$', 'c')),
    CONSTRAINT chk_credential_intake_provider CHECK (provider IN ('domainnameapi', 'hetzner', 'vultr')),
    CONSTRAINT chk_credential_intake_sealed CHECK (REGEXP_LIKE(sealed, '^[A-Za-z0-9_-]{64,8192}$', 'c')),
    CONSTRAINT chk_credential_intake_staff CHECK (REGEXP_LIKE(submitted_by, '^[0-9a-f]{32}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
