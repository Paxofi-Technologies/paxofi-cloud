-- Domain cost snapshots and provider sync runs (SRS PRC-011; commerce threat
-- model CD-13; THREAT-MODEL-DOMAIN-REGISTRAR.md DR-8). Every table is
-- append-only for the runtime user: a snapshot is never edited, a quarantined
-- snapshot is released by a staff decision recorded elsewhere, and a run is
-- written once when it ends. Costs are integer minor units, never floats.
-- Nullable columns are tested with IS NOT NULL inside CHECKs: MySQL treats an
-- UNKNOWN check result as passing.
CREATE TABLE domain_cost_snapshots (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    environment VARCHAR(8) CHARACTER SET ascii NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL,
    quarantine_reason VARCHAR(32) CHARACTER SET ascii NULL,
    item_count INT UNSIGNED NOT NULL,
    refused_count INT UNSIGNED NOT NULL,
    refused_summary JSON NOT NULL,
    fetched_at DATETIME(6) NOT NULL,
    KEY idx_domain_cost_snapshots_latest (provider, environment, status, fetched_at),
    CONSTRAINT chk_domain_cost_snapshots_provider CHECK (REGEXP_LIKE(provider, '^[a-z][a-z0-9_]{0,31}$', 'c')),
    CONSTRAINT chk_domain_cost_snapshots_environment CHECK (environment IN ('live', 'ote')),
    CONSTRAINT chk_domain_cost_snapshots_status CHECK (
        (status = 'accepted' AND quarantine_reason IS NULL)
        OR (status = 'quarantined' AND quarantine_reason IS NOT NULL AND quarantine_reason IN ('empty', 'items_disappeared', 'costs_jumped'))
    ),
    CONSTRAINT chk_domain_cost_snapshots_summary CHECK (JSON_TYPE(refused_summary) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE domain_cost_items (
    snapshot_id CHAR(32) CHARACTER SET ascii NOT NULL,
    tld VARCHAR(127) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    register_minor BIGINT UNSIGNED NOT NULL,
    renew_minor BIGINT UNSIGNED NOT NULL,
    transfer_minor BIGINT UNSIGNED NULL,
    restore_minor BIGINT UNSIGNED NULL,
    min_years TINYINT UNSIGNED NOT NULL,
    max_years TINYINT UNSIGNED NOT NULL,
    provider_status VARCHAR(32) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (snapshot_id, tld),
    CONSTRAINT fk_domain_cost_items_snapshot FOREIGN KEY (snapshot_id) REFERENCES domain_cost_snapshots (id),
    CONSTRAINT chk_domain_cost_items_tld CHECK (REGEXP_LIKE(tld, '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$', 'c')),
    CONSTRAINT chk_domain_cost_items_currency CHECK (currency IN ('NGN', 'USD', 'EUR')),
    CONSTRAINT chk_domain_cost_items_amounts CHECK (
        register_minor BETWEEN 1 AND 100000000000
        AND renew_minor BETWEEN 1 AND 100000000000
        AND (transfer_minor IS NULL OR transfer_minor BETWEEN 1 AND 100000000000)
        AND (restore_minor IS NULL OR restore_minor BETWEEN 1 AND 100000000000)
    ),
    CONSTRAINT chk_domain_cost_items_years CHECK (min_years BETWEEN 1 AND 10 AND max_years BETWEEN min_years AND 10),
    CONSTRAINT chk_domain_cost_items_status CHECK (REGEXP_LIKE(provider_status, '^[a-z][a-z0-9_]{0,31}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE provider_sync_runs (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    provider VARCHAR(32) CHARACTER SET ascii NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    trigger_source VARCHAR(16) CHARACTER SET ascii NOT NULL,
    outcome VARCHAR(16) CHARACTER SET ascii NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii NULL,
    snapshot_id CHAR(32) CHARACTER SET ascii NULL,
    attempts TINYINT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    KEY idx_provider_sync_runs_latest (provider, kind, started_at),
    CONSTRAINT fk_provider_sync_runs_snapshot FOREIGN KEY (snapshot_id) REFERENCES domain_cost_snapshots (id),
    CONSTRAINT chk_provider_sync_runs_provider CHECK (REGEXP_LIKE(provider, '^[a-z][a-z0-9_]{0,31}$', 'c')),
    CONSTRAINT chk_provider_sync_runs_kind CHECK (kind IN ('domain_costs')),
    CONSTRAINT chk_provider_sync_runs_trigger CHECK (trigger_source IN ('schedule', 'manual', 'cli')),
    CONSTRAINT chk_provider_sync_runs_outcome CHECK (
        (outcome IN ('accepted', 'quarantined') AND snapshot_id IS NOT NULL AND reason IS NULL)
        OR (outcome = 'failed' AND snapshot_id IS NULL AND reason IS NOT NULL AND REGEXP_LIKE(reason, '^[a-z][a-z_]{0,31}$', 'c'))
    ),
    CONSTRAINT chk_provider_sync_runs_attempts CHECK (attempts BETWEEN 1 AND 4),
    CONSTRAINT chk_provider_sync_runs_times CHECK (finished_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
