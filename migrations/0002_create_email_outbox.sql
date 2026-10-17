-- Transactional email outbox (threat model THREAT-MODEL-TRANSACTIONAL-EMAIL
-- E-2..E-5). `payload` is sealed to the mail worker's public key
-- (crypto_box_seal) and holds the recipient and template parameters,
-- including one-time tokens; it is purged when the row reaches a final state,
-- which the CHECK constraint enforces. Logs and lookups use recipient_hash.
CREATE TABLE email_outbox (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    template VARCHAR(64) CHARACTER SET ascii NOT NULL,
    recipient_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    payload TEXT CHARACTER SET ascii NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    locked_until DATETIME(6) NULL,
    provider VARCHAR(16) CHARACTER SET ascii NULL,
    provider_message_id VARCHAR(128) CHARACTER SET ascii NULL,
    last_error VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    sent_at DATETIME(6) NULL,
    KEY idx_email_outbox_due (status, next_attempt_at),
    KEY idx_email_outbox_sent (sent_at),
    KEY idx_email_outbox_recipient (recipient_hash),
    CONSTRAINT chk_email_outbox_status CHECK (status IN ('pending', 'sending', 'sent', 'dead')),
    CONSTRAINT chk_email_outbox_purged CHECK ((status IN ('sent', 'dead')) = (payload IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
