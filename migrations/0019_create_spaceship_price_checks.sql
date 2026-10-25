-- Spaceship as a registrar (THREAT-MODEL-DOMAIN-PRICING.md §8, SP-1 to SP-7):
-- its credential may be entered in the admin console, and its price checks
-- are kept. A check asks Spaceship about one ordinary test name per extension
-- and records whether a price came back; it never feeds the cost list.
-- Append-only for the runtime user. Test names, Spaceship's message text and
-- the key are never stored.
ALTER TABLE provider_credential_intake DROP CHECK chk_credential_intake_provider;
ALTER TABLE provider_credential_intake ADD CONSTRAINT chk_credential_intake_provider CHECK (provider IN ('domainnameapi', 'namecheap', 'dynadot', 'spaceship', 'hetzner', 'vultr'));

CREATE TABLE spaceship_price_checks (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    trigger_kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    checked_at DATETIME(6) NOT NULL,
    outcome VARCHAR(16) CHARACTER SET ascii NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii NULL,
    names_asked TINYINT UNSIGNED NOT NULL,
    names_answered TINYINT UNSIGNED NOT NULL,
    names_priced TINYINT UNSIGNED NOT NULL,
    KEY idx_spaceship_price_checks_latest (checked_at),
    CONSTRAINT chk_spaceship_price_checks_trigger CHECK (trigger_kind IN ('manual', 'cli')),
    CONSTRAINT chk_spaceship_price_checks_outcome CHECK ((outcome = 'done' AND reason IS NULL) OR (outcome = 'failed' AND reason IS NOT NULL AND REGEXP_LIKE(reason, '^[a-z][a-z_]{0,31}$', 'c'))),
    CONSTRAINT chk_spaceship_price_checks_counts CHECK (names_asked <= 20 AND names_answered <= names_asked AND names_priced <= names_answered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE spaceship_price_check_items (
    check_id CHAR(32) CHARACTER SET ascii NOT NULL,
    tld VARCHAR(63) CHARACTER SET ascii NOT NULL,
    result VARCHAR(32) CHARACTER SET ascii NOT NULL,
    register_minor BIGINT UNSIGNED NULL,
    other_priced VARCHAR(160) CHARACTER SET ascii NULL,
    PRIMARY KEY (check_id, tld),
    CONSTRAINT fk_spaceship_price_check_items_check FOREIGN KEY (check_id) REFERENCES spaceship_price_checks (id),
    -- An extension only, never a test name (SP-7).
    CONSTRAINT chk_spaceship_price_check_items_tld CHECK (REGEXP_LIKE(tld, '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)?$', 'c') AND tld NOT LIKE '%price-check%'),
    CONSTRAINT chk_spaceship_price_check_items_result CHECK (REGEXP_LIKE(result, '^[a-z][a-z_]{0,31}$', 'c')),
    CONSTRAINT chk_spaceship_price_check_items_price CHECK (register_minor IS NULL OR register_minor <= 100000000000),
    CONSTRAINT chk_spaceship_price_check_items_other CHECK (other_priced IS NULL OR REGEXP_LIKE(other_priced, '^[a-z][a-z_]{0,31}(,[a-z][a-z_]{0,31}){0,4}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
