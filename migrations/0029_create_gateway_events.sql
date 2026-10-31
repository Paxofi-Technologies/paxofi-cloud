-- Gateway webhook events (payments threat model PY-7, PT-15, PT-19). One row
-- per genuine webhook (signature already checked), written by payments-web
-- before it answers 200. The unique (gateway, event_key) key makes retries
-- and replays a no-op. The payload itself is never stored: it carries the
-- customer's email and card metadata, so only its SHA-256 is kept. A webhook
-- only brings the next verify check forward (PY-8); this table never moves
-- money. Append-only for the runtime user.
CREATE TABLE gateway_events (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    gateway VARCHAR(16) CHARACTER SET ascii NOT NULL,
    event_key CHAR(64) CHARACTER SET ascii NOT NULL,
    event VARCHAR(48) CHARACTER SET ascii NOT NULL,
    reference VARCHAR(30) CHARACTER SET ascii NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
    received_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_gateway_events_key (gateway, event_key),
    KEY idx_gateway_events_reference (reference),
    CONSTRAINT chk_gateway_events_gateway CHECK (gateway IN ('paystack', 'flutterwave')),
    CONSTRAINT chk_gateway_events_key CHECK (event_key REGEXP '^[0-9a-f]{64}$' AND payload_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_gateway_events_reference CHECK (reference REGEXP '^PXC-[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
    CONSTRAINT chk_gateway_events_event CHECK (event REGEXP '^[a-z]+([.][a-z_]+){1,2}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
