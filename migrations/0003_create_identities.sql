-- Customer identities, personal billing accounts and email verification
-- (SRS IAM-001, IAM-002; threat model THREAT-MODEL-IDENTITY D-2, D-3, T-01..T-05,
-- T-46). Emails are looked up by `email_lookup` (SHA-256 of the lower-cased
-- address) so a unique index never depends on collation rules. Verification
-- tokens are stored only as SHA-256 hashes.
CREATE TABLE identities (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii NOT NULL,
    email_lookup CHAR(64) CHARACTER SET ascii NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone_e164 VARCHAR(16) CHARACTER SET ascii NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT 'pending_verification',
    terms_version VARCHAR(32) CHARACTER SET ascii NOT NULL,
    terms_accepted_at DATETIME(6) NOT NULL,
    verified_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_identities_email_lookup (email_lookup),
    CONSTRAINT chk_identities_status CHECK (status IN ('pending_verification', 'active', 'suspended')),
    CONSTRAINT chk_identities_active_verified CHECK (status <> 'active' OR verified_at IS NOT NULL),
    CONSTRAINT chk_identities_argon2id CHECK (password_hash LIKE '$argon2id$%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The billing account (tenant). Every customer gets a personal one at
-- registration; business accounts and invitations come later.
CREATE TABLE accounts (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    type VARCHAR(16) CHARACTER SET ascii NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_accounts_type CHECK (type IN ('personal', 'business'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE account_members (
    account_id CHAR(32) CHARACTER SET ascii NOT NULL,
    identity_id CHAR(32) CHARACTER SET ascii NOT NULL,
    role VARCHAR(16) CHARACTER SET ascii NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (account_id, identity_id),
    KEY idx_account_members_identity (identity_id),
    CONSTRAINT fk_account_members_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_account_members_identity FOREIGN KEY (identity_id) REFERENCES identities (id),
    CONSTRAINT chk_account_members_role CHECK (role IN ('owner', 'admin', 'billing', 'technical', 'read_only'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE email_verification_tokens (
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    identity_id CHAR(32) CHARACTER SET ascii NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_email_verification_identity (identity_id),
    CONSTRAINT fk_email_verification_identity FOREIGN KEY (identity_id) REFERENCES identities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
