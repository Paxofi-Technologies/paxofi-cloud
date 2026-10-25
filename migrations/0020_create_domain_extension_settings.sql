-- Domain extensions (THREAT-MODEL-DOMAIN-PRICING.md DX-3, DX-5, DX-9, DX-10).
-- domain_pricing_settings: one row, entered by staff in the admin console;
-- margins are never committed to the repository (public). Until it exists,
-- nothing is priced. domain_extension_settings: per-extension pin, featured
-- flag and order, label, grace and redemption. Every change is audited.
CREATE TABLE domain_pricing_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    markup_bp INT UNSIGNED NOT NULL,
    min_margin_bp INT UNSIGNED NOT NULL,
    usd_step_minor INT UNSIGNED NOT NULL,
    ngn_step_minor INT UNSIGNED NOT NULL,
    updated_by CHAR(32) CHARACTER SET ascii NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_domain_pricing_settings_single CHECK (id = 1),
    CONSTRAINT chk_domain_pricing_settings_margin CHECK (min_margin_bp <= 10000 AND markup_bp <= 100000 AND min_margin_bp <= markup_bp),
    CONSTRAINT chk_domain_pricing_settings_steps CHECK (usd_step_minor BETWEEN 1 AND 10000000 AND ngn_step_minor BETWEEN 1 AND 10000000),
    CONSTRAINT fk_domain_pricing_settings_staff FOREIGN KEY (updated_by) REFERENCES staff_members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE domain_extension_settings (
    tld VARCHAR(127) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    pinned_registrar VARCHAR(32) CHARACTER SET ascii NULL,
    featured_order SMALLINT UNSIGNED NULL,
    label VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'none',
    grace_days TINYINT UNSIGNED NULL,
    grace_fee_minor BIGINT UNSIGNED NULL,
    redemption_days TINYINT UNSIGNED NULL,
    redemption_fee_minor BIGINT UNSIGNED NULL,
    updated_by CHAR(32) CHARACTER SET ascii NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_domain_extension_settings_tld CHECK (REGEXP_LIKE(tld, '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$', 'c')),
    CONSTRAINT chk_domain_extension_settings_pin CHECK (pinned_registrar IS NULL OR pinned_registrar IN ('domainnameapi', 'namecheap', 'dynadot', 'spaceship')),
    CONSTRAINT chk_domain_extension_settings_label CHECK (label IN ('none', 'hot', 'new', 'sale')),
    CONSTRAINT chk_domain_extension_settings_days CHECK ((grace_days IS NULL OR grace_days <= 90) AND (redemption_days IS NULL OR redemption_days <= 90)),
    CONSTRAINT chk_domain_extension_settings_fees CHECK ((grace_fee_minor IS NULL OR grace_fee_minor <= 100000000) AND (redemption_fee_minor IS NULL OR redemption_fee_minor <= 100000000)),
    CONSTRAINT fk_domain_extension_settings_staff FOREIGN KEY (updated_by) REFERENCES staff_members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- CR-003: the launch headline extensions, in display order.
INSERT INTO domain_extension_settings (tld, featured_order, updated_at) VALUES
    ('ng', 1, UTC_TIMESTAMP(6)), ('com.ng', 2, UTC_TIMESTAMP(6)), ('com', 3, UTC_TIMESTAMP(6)), ('org', 4, UTC_TIMESTAMP(6)), ('site', 5, UTC_TIMESTAMP(6));
