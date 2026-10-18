-- Devices an identity has signed in from (SRS IAM-017; threat model
-- THREAT-MODEL-IDENTITY T-55, §6.15). Only SHA-256 of the random device
-- cookie is stored. A known device changes which email is sent after
-- sign-in and nothing else: it never skips a password or MFA check.
CREATE TABLE identity_devices (
    identity_id CHAR(32) CHARACTER SET ascii NOT NULL,
    device_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    label VARCHAR(100) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (identity_id, device_hash),
    CONSTRAINT fk_identity_devices_identity FOREIGN KEY (identity_id) REFERENCES identities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
