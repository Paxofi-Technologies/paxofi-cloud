-- Staff invitations and passkeys (SRS IAM-008, ACC-005; threat model
-- THREAT-MODEL-IDENTITY D-10, D-11, T-29, T-30, §6.17). Invitation tokens are
-- stored only as SHA-256. A passkey row holds the public COSE key, never a
-- secret. Credential IDs (up to 1023 bytes) are unique through their SHA-256.
ALTER TABLE staff_members
    ADD COLUMN webauthn_user_handle BINARY(32) NOT NULL,
    ADD UNIQUE KEY uq_staff_members_user_handle (webauthn_user_handle);

CREATE TABLE staff_invitations (
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    staff_id CHAR(32) CHARACTER SET ascii NOT NULL,
    -- NULL only for the operator bootstrap of the first Security Admin.
    invited_by CHAR(32) CHARACTER SET ascii NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    KEY idx_staff_invitations_staff (staff_id),
    CONSTRAINT fk_staff_invitations_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id),
    CONSTRAINT fk_staff_invitations_invited_by FOREIGN KEY (invited_by) REFERENCES staff_members (id),
    CONSTRAINT chk_staff_invitations_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_staff_invitations_not_self CHECK (invited_by IS NULL OR invited_by <> staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE staff_passkeys (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    staff_id CHAR(32) CHARACTER SET ascii NOT NULL,
    credential_id VARBINARY(1023) NOT NULL,
    credential_lookup CHAR(64) CHARACTER SET ascii NOT NULL,
    public_key VARBINARY(1024) NOT NULL,
    algorithm SMALLINT NOT NULL,
    sign_count BIGINT UNSIGNED NOT NULL,
    aaguid CHAR(36) CHARACTER SET ascii NOT NULL,
    transports VARCHAR(128) CHARACTER SET ascii NOT NULL,
    backup_eligible TINYINT(1) NOT NULL,
    backup_state TINYINT(1) NOT NULL,
    label VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_used_at DATETIME(6) NULL,
    disabled_at DATETIME(6) NULL,
    disabled_reason VARCHAR(32) CHARACTER SET ascii NULL,
    UNIQUE KEY uq_staff_passkeys_credential (credential_lookup),
    KEY idx_staff_passkeys_staff (staff_id),
    CONSTRAINT fk_staff_passkeys_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id),
    -- ES256 and EdDSA only (D-10).
    CONSTRAINT chk_staff_passkeys_algorithm CHECK (algorithm IN (-7, -8)),
    CONSTRAINT chk_staff_passkeys_disabled CHECK ((disabled_at IS NULL) = (disabled_reason IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
