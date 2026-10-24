-- Items a domain cost sync could not use, kept with their snapshot so staff
-- can see what is not on sale and why (THREAT-MODEL-DOMAIN-REGISTRAR.md
-- DR-14). Name and detail come from the registrar and are untrusted: the
-- application stores them only when they match strict patterns, and the
-- CHECKs below hold the same line. Append-only for the runtime user.
CREATE TABLE domain_cost_skipped (
    snapshot_id CHAR(32) CHARACTER SET ascii NOT NULL,
    seq SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(127) CHARACTER SET ascii NULL,
    reason VARCHAR(32) CHARACTER SET ascii NOT NULL,
    detail VARCHAR(16) CHARACTER SET ascii NULL,
    PRIMARY KEY (snapshot_id, seq),
    CONSTRAINT fk_domain_cost_skipped_snapshot FOREIGN KEY (snapshot_id) REFERENCES domain_cost_snapshots (id),
    CONSTRAINT chk_domain_cost_skipped_seq CHECK (seq < 1000),
    CONSTRAINT chk_domain_cost_skipped_name CHECK (name IS NULL OR REGEXP_LIKE(name, '^[a-z0-9][a-z0-9.-]{0,126}$', 'c')),
    CONSTRAINT chk_domain_cost_skipped_reason CHECK (REGEXP_LIKE(reason, '^[a-z][a-z_]{0,31}$', 'c')),
    CONSTRAINT chk_domain_cost_skipped_detail CHECK (detail IS NULL OR REGEXP_LIKE(detail, '^[A-Za-z0-9_:]{1,16}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
