-- Exchange rates (SRS PRC-013; commerce threat model CD-16, C-27). One row
-- per fetched rate set and one row per currency in it, both append-only for
-- the runtime user: a held set is never edited into an accepted one; a
-- Finance confirmation or override is a new set. Rates are decimal strings,
-- never floating point (CD-14).
CREATE TABLE fx_rate_sets (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    source VARCHAR(40) CHARACTER SET ascii NOT NULL,
    verdict VARCHAR(16) CHARACTER SET ascii NOT NULL,
    published_at DATETIME(6) NOT NULL,
    fetched_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    KEY idx_fx_rate_sets_verdict (verdict, fetched_at),
    CONSTRAINT chk_fx_rate_sets_source CHECK (REGEXP_LIKE(source, '^[a-z0-9][a-z0-9-]{0,39}$', 'c')),
    CONSTRAINT chk_fx_rate_sets_verdict CHECK (verdict IN ('accepted', 'held'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fx_rate_values (
    set_id CHAR(32) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    per_usd VARCHAR(22) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (set_id, currency),
    CONSTRAINT fk_fx_rate_values_set FOREIGN KEY (set_id) REFERENCES fx_rate_sets (id),
    CONSTRAINT chk_fx_rate_values_currency CHECK (currency IN ('NGN', 'USD', 'EUR')),
    CONSTRAINT chk_fx_rate_values_rate CHECK (REGEXP_LIKE(per_usd, '^(0|[1-9][0-9]{0,8})(\\.[0-9]{1,12})?$', 'c') AND per_usd NOT IN ('0'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
