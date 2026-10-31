-- Payment verification (payments threat model PY-8, PY-9, PY-11). The payments
-- worker re-checks open attempts with the gateway on a fixed schedule; a
-- confirmed payment records the gateway transaction, fee and time once. A
-- gateway transaction can be credited to one attempt only.
ALTER TABLE payment_attempts
    ADD COLUMN gateway_transaction_id VARCHAR(20) CHARACTER SET ascii NULL AFTER failure_reason,
    ADD COLUMN paid_minor BIGINT NULL AFTER gateway_transaction_id,
    ADD COLUMN paid_currency CHAR(3) CHARACTER SET ascii NULL AFTER paid_minor,
    ADD COLUMN fee_minor BIGINT NULL AFTER paid_currency,
    ADD COLUMN paid_at DATETIME(6) NULL AFTER fee_minor,
    ADD COLUMN checks SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER paid_at,
    ADD COLUMN next_check_at DATETIME(6) NULL AFTER checks,
    ADD UNIQUE KEY uq_payment_attempts_gateway_tx (gateway, gateway_transaction_id),
    ADD KEY idx_payment_attempts_due (state, next_check_at),
    ADD CONSTRAINT chk_payment_attempts_gateway_tx CHECK (gateway_transaction_id IS NULL OR gateway_transaction_id REGEXP '^[0-9]{1,20}$'),
    ADD CONSTRAINT chk_payment_attempts_paid CHECK (paid_minor IS NULL OR (paid_minor > 0 AND paid_minor <= 10000000000000 AND paid_currency IN ('NGN', 'USD'))),
    ADD CONSTRAINT chk_payment_attempts_fee CHECK (fee_minor IS NULL OR (fee_minor >= 0 AND fee_minor <= 10000000000000));

-- Attempts already waiting on the customer get their first check now.
UPDATE payment_attempts SET next_check_at = UTC_TIMESTAMP(6) WHERE state IN ('pending', 'unknown');
