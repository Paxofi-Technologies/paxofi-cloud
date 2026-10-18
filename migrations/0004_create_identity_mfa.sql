-- TOTP two-step verification and recovery codes (SRS IAM-007, IAM-013;
-- threat model THREAT-MODEL-IDENTITY D-4, D-5, T-13..T-17, §6.13).
-- The TOTP secret is stored only as a SecretCipher envelope bound to the
-- identity; recovery codes only as keyed BLAKE2b hashes.
CREATE TABLE identity_totp (
    identity_id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    secret_envelope VARCHAR(255) CHARACTER SET ascii NOT NULL,
    confirmed_at DATETIME(6) NULL,
    last_used_step BIGINT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_identity_totp_identity FOREIGN KEY (identity_id) REFERENCES identities (id),
    CONSTRAINT chk_identity_totp_envelope CHECK (secret_envelope LIKE 'v1.%'),
    CONSTRAINT chk_identity_totp_step CHECK (last_used_step IS NULL OR confirmed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE identity_recovery_codes (
    code_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    identity_id CHAR(32) CHARACTER SET ascii NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_identity_recovery_codes_identity (identity_id),
    CONSTRAINT fk_identity_recovery_codes_identity FOREIGN KEY (identity_id) REFERENCES identities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
