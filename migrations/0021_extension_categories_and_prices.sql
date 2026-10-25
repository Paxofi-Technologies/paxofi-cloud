-- Domain extensions: category for the public price list tabs (DS-12) and
-- staff price overrides per action (THREAT-MODEL-DOMAIN-PRICING.md DX-14).
-- Overrides are integer minor units; NULL means the markup price. They are
-- checked against the floor on save and again at every quote.
ALTER TABLE domain_extension_settings
    ADD COLUMN category VARCHAR(16) CHARACTER SET ascii NULL AFTER label,
    ADD COLUMN usd_register_minor BIGINT UNSIGNED NULL,
    ADD COLUMN usd_renew_minor BIGINT UNSIGNED NULL,
    ADD COLUMN usd_transfer_minor BIGINT UNSIGNED NULL,
    ADD COLUMN ngn_register_minor BIGINT UNSIGNED NULL,
    ADD COLUMN ngn_renew_minor BIGINT UNSIGNED NULL,
    ADD COLUMN ngn_transfer_minor BIGINT UNSIGNED NULL,
    ADD CONSTRAINT chk_domain_extension_settings_category CHECK (category IS NULL OR category IN (
        'business', 'technology', 'geographic', 'arts', 'sports', 'services', 'finance',
        'education', 'food', 'leisure', 'shopping', 'real_estate', 'novelty', 'other')),
    ADD CONSTRAINT chk_domain_extension_settings_prices CHECK (
        (usd_register_minor IS NULL OR usd_register_minor BETWEEN 1 AND 10000000000)
        AND (usd_renew_minor IS NULL OR usd_renew_minor BETWEEN 1 AND 10000000000)
        AND (usd_transfer_minor IS NULL OR usd_transfer_minor BETWEEN 1 AND 10000000000)
        AND (ngn_register_minor IS NULL OR ngn_register_minor BETWEEN 1 AND 10000000000)
        AND (ngn_renew_minor IS NULL OR ngn_renew_minor BETWEEN 1 AND 10000000000)
        AND (ngn_transfer_minor IS NULL OR ngn_transfer_minor BETWEEN 1 AND 10000000000));
