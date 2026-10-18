-- Security audit log (SRS AUD-001, AUD-002, AUD-004; threat model
-- THREAT-MODEL-IDENTITY D-14, T-39, T-50, T-51, §6.14).
-- audit_events is append-only and hash-chained: every row stores
-- SHA-256(prev_hash || canonical JSON of the row). Writers lock the single
-- audit_chain_head row, so seq has no gaps. `details` keeps the exact
-- canonical JSON string (not a JSON column, which would reorder keys).
CREATE TABLE audit_events (
    seq BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    id CHAR(32) CHARACTER SET ascii NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii NOT NULL,
    outcome VARCHAR(16) CHARACTER SET ascii NOT NULL,
    identity_id CHAR(32) CHARACTER SET ascii NULL,
    account_id CHAR(32) CHARACTER SET ascii NULL,
    request_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    details VARCHAR(1024) CHARACTER SET ascii NOT NULL,
    context_commitment CHAR(64) CHARACTER SET ascii NULL,
    prev_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    hash CHAR(64) CHARACTER SET ascii NOT NULL,
    UNIQUE KEY uq_audit_events_id (id),
    KEY idx_audit_events_identity (identity_id, seq),
    CONSTRAINT chk_audit_events_outcome CHECK (outcome IN ('success', 'failure'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE audit_chain_head (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    seq BIGINT UNSIGNED NOT NULL,
    hash CHAR(64) CHARACTER SET ascii NOT NULL,
    CONSTRAINT chk_audit_chain_head_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO audit_chain_head (id, seq, hash) VALUES (1, 0, '0000000000000000000000000000000000000000000000000000000000000000');

-- Personal request context (T-51): purgeable after 180 days without breaking
-- the chain; the event keeps only SHA-256(salt || canonical context).
CREATE TABLE audit_event_context (
    event_seq BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    salt CHAR(32) CHARACTER SET ascii NOT NULL,
    ip VARCHAR(45) CHARACTER SET ascii NOT NULL,
    ip_network VARCHAR(49) CHARACTER SET ascii NOT NULL,
    country CHAR(2) CHARACTER SET ascii NULL,
    region VARCHAR(64) NULL,
    city VARCHAR(64) NULL,
    user_agent VARCHAR(512) NOT NULL,
    browser VARCHAR(32) CHARACTER SET ascii NOT NULL,
    browser_version VARCHAR(16) CHARACTER SET ascii NOT NULL,
    os VARCHAR(32) CHARACTER SET ascii NOT NULL,
    os_version VARCHAR(16) CHARACTER SET ascii NOT NULL,
    device_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
    created_at DATETIME(6) NOT NULL,
    KEY idx_audit_event_context_created (created_at),
    CONSTRAINT fk_audit_event_context_event FOREIGN KEY (event_seq) REFERENCES audit_events (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
