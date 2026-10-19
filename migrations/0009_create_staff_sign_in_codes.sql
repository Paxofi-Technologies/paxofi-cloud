-- Staff sign-in with password, authenticator code and emailed code; passkeys
-- optional (CEO decision 19 Oct 2026; threat model THREAT-MODEL-IDENTITY
-- §6.20, R-5). Mirrors the customer TOTP tables (0004) in a separate domain:
-- a staff secret is bound to the staff ID, never usable as a customer's.
-- NULL password: a member enrolled with passkeys only before this change.
ALTER TABLE staff_members
    ADD COLUMN password_hash VARCHAR(255) CHARACTER SET ascii NULL,
    ADD CONSTRAINT chk_staff_members_argon2id CHECK (password_hash IS NULL OR password_hash LIKE '$argon2id$%');

CREATE TABLE staff_totp (
    staff_id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    secret_envelope VARCHAR(255) CHARACTER SET ascii NOT NULL,
    confirmed_at DATETIME(6) NULL,
    last_used_step BIGINT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_staff_totp_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id),
    CONSTRAINT chk_staff_totp_envelope CHECK (secret_envelope LIKE 'v1.%'),
    CONSTRAINT chk_staff_totp_step CHECK (last_used_step IS NULL OR confirmed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE staff_recovery_codes (
    code_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    staff_id CHAR(32) CHARACTER SET ascii NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_staff_recovery_codes_staff (staff_id),
    CONSTRAINT fk_staff_recovery_codes_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
