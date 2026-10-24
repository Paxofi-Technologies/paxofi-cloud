-- The field paths a registrar price list contained, per snapshot
-- (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-16), so staff can see what the
-- registrar sends (for example grace or redemption periods) before any
-- parser change relies on it. Names only: no text values are stored, only
-- the kinds of value seen and, for small whole numbers, the range.
-- Append-only for the runtime user.
CREATE TABLE domain_cost_feed_fields (
    snapshot_id CHAR(32) CHARACTER SET ascii NOT NULL,
    path VARCHAR(160) CHARACTER SET ascii NOT NULL,
    items INT UNSIGNED NOT NULL,
    kinds VARCHAR(40) CHARACTER SET ascii NOT NULL,
    min_value INT UNSIGNED NULL,
    max_value INT UNSIGNED NULL,
    PRIMARY KEY (snapshot_id, path),
    CONSTRAINT fk_domain_cost_feed_fields_snapshot FOREIGN KEY (snapshot_id) REFERENCES domain_cost_snapshots (id),
    CONSTRAINT chk_domain_cost_feed_fields_path CHECK (REGEXP_LIKE(path, '^[A-Za-z(][A-Za-z0-9_.()\\[\\]]{0,159}$', 'c')),
    CONSTRAINT chk_domain_cost_feed_fields_items CHECK (items >= 1),
    CONSTRAINT chk_domain_cost_feed_fields_kinds CHECK (REGEXP_LIKE(kinds, '^(text|object|list|bool|null)(,(text|object|list|bool|null)){0,4}$', 'c')),
    CONSTRAINT chk_domain_cost_feed_fields_range CHECK ((min_value IS NULL AND max_value IS NULL) OR (min_value IS NOT NULL AND max_value IS NOT NULL AND min_value <= max_value AND max_value <= 99999))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
