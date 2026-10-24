-- "Sync now" requests from the admin console (THREAT-MODEL-DOMAIN-REGISTRAR.md
-- DR-12). The web tier only inserts a row; the provider worker claims unclaimed
-- rows by setting picked_up_at and runs the sync. Rows are never deleted, so
-- the 10-minute limit and the history survive. The runtime user may UPDATE
-- only so the worker can mark a claim.
CREATE TABLE provider_sync_requests (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    requested_by CHAR(32) CHARACTER SET ascii NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    picked_up_at DATETIME(6) NULL,
    KEY idx_provider_sync_requests_latest (provider, kind, requested_at),
    KEY idx_provider_sync_requests_open (provider, kind, picked_up_at),
    CONSTRAINT fk_provider_sync_requests_staff FOREIGN KEY (requested_by) REFERENCES staff_members (id),
    CONSTRAINT chk_provider_sync_requests_provider CHECK (REGEXP_LIKE(provider, '^[a-z][a-z0-9_]{0,31}$', 'c')),
    CONSTRAINT chk_provider_sync_requests_kind CHECK (kind IN ('domain_costs')),
    CONSTRAINT chk_provider_sync_requests_picked CHECK (picked_up_at IS NULL OR picked_up_at >= requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
