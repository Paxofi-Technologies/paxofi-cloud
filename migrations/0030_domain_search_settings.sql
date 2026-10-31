-- Domain search settings (THREAT-MODEL-DOMAIN-SEARCH.md DS-14 to DS-17, DS-21 to DS-23).
-- domain_search_settings: one row, the extensions checked with every search, how many
-- name ideas to show and the idea words. Until it exists, built-in defaults apply.
-- domain_lookup_settings: per extension, which source answers availability and an
-- optional fixed WHOIS server and "no match" phrase. Every change is audited.
CREATE TABLE domain_search_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    search_tlds JSON NOT NULL,
    max_ideas TINYINT UNSIGNED NOT NULL,
    idea_prefixes JSON NOT NULL,
    idea_suffixes JSON NOT NULL,
    updated_by CHAR(32) CHARACTER SET ascii NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_domain_search_settings_single CHECK (id = 1),
    CONSTRAINT chk_domain_search_settings_ideas CHECK (max_ideas <= 30),
    CONSTRAINT chk_domain_search_settings_json CHECK (JSON_TYPE(search_tlds) = 'ARRAY' AND JSON_LENGTH(search_tlds) <= 20
        AND JSON_TYPE(idea_prefixes) = 'ARRAY' AND JSON_LENGTH(idea_prefixes) <= 12
        AND JSON_TYPE(idea_suffixes) = 'ARRAY' AND JSON_LENGTH(idea_suffixes) <= 12),
    CONSTRAINT fk_domain_search_settings_staff FOREIGN KEY (updated_by) REFERENCES staff_members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE domain_lookup_settings (
    tld VARCHAR(127) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    mode VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'auto',
    whois_server VARCHAR(253) CHARACTER SET ascii NULL,
    whois_phrase VARCHAR(100) CHARACTER SET ascii NULL,
    updated_by CHAR(32) CHARACTER SET ascii NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_domain_lookup_settings_tld CHECK (REGEXP_LIKE(tld, '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){0,2}$', 'c')),
    CONSTRAINT chk_domain_lookup_settings_mode CHECK (mode IN ('auto', 'rdap', 'whois', 'registrar')),
    CONSTRAINT chk_domain_lookup_settings_server CHECK (whois_server IS NULL OR REGEXP_LIKE(whois_server, '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$', 'c')),
    CONSTRAINT chk_domain_lookup_settings_phrase CHECK (whois_phrase IS NULL OR CHAR_LENGTH(whois_phrase) BETWEEN 3 AND 100),
    CONSTRAINT fk_domain_lookup_settings_staff FOREIGN KEY (updated_by) REFERENCES staff_members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
