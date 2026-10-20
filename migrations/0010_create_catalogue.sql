-- Product catalogue (SRS CAT-001…004, PRC-002, PRC-004…006; commerce threat
-- model CD-4, CD-5, CD-11). Products → plans → billing cycles → typed options.
-- Customer-facing data lives here; the provider mapping and provider cost are
-- in their own tables, which no customer-facing query joins (CAT-003, PRC-004).
-- Prices are effective-dated rows; nothing ever rewrites a past price (PRC-005).
-- Every CHECK guards NULLs, because a CHECK that evaluates to NULL passes.
CREATE TABLE catalogue_products (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    slug VARCHAR(64) CHARACTER SET ascii NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_catalogue_products_slug (slug),
    CONSTRAINT chk_catalogue_products_slug CHECK (REGEXP_LIKE(slug, '^[a-z0-9][a-z0-9-]{0,63}$', 'c')),
    CONSTRAINT chk_catalogue_products_kind CHECK (kind IN ('domain', 'hosting', 'vps'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE catalogue_plans (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    product_id CHAR(32) CHARACTER SET ascii NOT NULL,
    slug VARCHAR(64) CHARACTER SET ascii NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    state VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'draft',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_catalogue_plans_slug (slug),
    KEY idx_catalogue_plans_product (product_id, sort_order),
    CONSTRAINT fk_catalogue_plans_product FOREIGN KEY (product_id) REFERENCES catalogue_products (id),
    CONSTRAINT chk_catalogue_plans_slug CHECK (REGEXP_LIKE(slug, '^[a-z0-9][a-z0-9-]{0,63}$', 'c')),
    CONSTRAINT chk_catalogue_plans_state CHECK (state IN ('draft', 'active', 'limited', 'deprecated', 'retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The billing cycles a plan is sold on (BR-09).
CREATE TABLE catalogue_plan_cycles (
    plan_id CHAR(32) CHARACTER SET ascii NOT NULL,
    cycle VARCHAR(16) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (plan_id, cycle),
    CONSTRAINT fk_catalogue_plan_cycles_plan FOREIGN KEY (plan_id) REFERENCES catalogue_plans (id),
    CONSTRAINT chk_catalogue_plan_cycles_cycle CHECK (cycle IN ('monthly', 'annual', 'biennial', 'triennial'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Sell prices: first term and renewal, per cycle and currency, from a date.
CREATE TABLE catalogue_prices (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    plan_id CHAR(32) CHARACTER SET ascii NOT NULL,
    cycle VARCHAR(16) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    first_term_minor BIGINT NOT NULL,
    renewal_minor BIGINT NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_catalogue_prices_point (plan_id, cycle, currency, effective_from),
    CONSTRAINT fk_catalogue_prices_cycle FOREIGN KEY (plan_id, cycle) REFERENCES catalogue_plan_cycles (plan_id, cycle),
    CONSTRAINT chk_catalogue_prices_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_catalogue_prices_first CHECK (first_term_minor BETWEEN 0 AND 10000000000000),
    CONSTRAINT chk_catalogue_prices_renewal CHECK (renewal_minor BETWEEN 0 AND 10000000000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Typed options (CAT-004): enum (choices below), integer range, boolean.
CREATE TABLE catalogue_plan_options (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    plan_id CHAR(32) CHARACTER SET ascii NOT NULL,
    code VARCHAR(32) CHARACTER SET ascii NOT NULL,
    label VARCHAR(120) NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    min_value INT NULL,
    max_value INT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_catalogue_plan_options_code (plan_id, code),
    CONSTRAINT fk_catalogue_plan_options_plan FOREIGN KEY (plan_id) REFERENCES catalogue_plans (id),
    CONSTRAINT chk_catalogue_plan_options_code CHECK (REGEXP_LIKE(code, '^[a-z][a-z0-9_]{0,31}$', 'c')),
    CONSTRAINT chk_catalogue_plan_options_kind CHECK (kind IN ('enum', 'integer', 'boolean')),
    CONSTRAINT chk_catalogue_plan_options_range CHECK (
        (kind = 'integer' AND min_value IS NOT NULL AND max_value IS NOT NULL AND min_value >= 0 AND max_value >= min_value AND max_value <= 1000)
        OR (kind <> 'integer' AND min_value IS NULL AND max_value IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE catalogue_option_choices (
    option_id CHAR(32) CHARACTER SET ascii NOT NULL,
    code VARCHAR(32) CHARACTER SET ascii NOT NULL,
    label VARCHAR(120) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (option_id, code),
    CONSTRAINT fk_catalogue_option_choices_option FOREIGN KEY (option_id) REFERENCES catalogue_plan_options (id),
    CONSTRAINT chk_catalogue_option_choices_code CHECK (REGEXP_LIKE(code, '^[a-z0-9][a-z0-9_-]{0,31}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Price modifiers per billing period: per enum choice, per integer unit
-- (choice_code ''), or for a boolean option when on (choice_code '').
CREATE TABLE catalogue_option_prices (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    option_id CHAR(32) CHARACTER SET ascii NOT NULL,
    choice_code VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
    cycle VARCHAR(16) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    amount_minor BIGINT NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_catalogue_option_prices_point (option_id, choice_code, cycle, currency, effective_from),
    CONSTRAINT fk_catalogue_option_prices_option FOREIGN KEY (option_id) REFERENCES catalogue_plan_options (id),
    CONSTRAINT chk_catalogue_option_prices_cycle CHECK (cycle IN ('monthly', 'annual', 'biennial', 'triennial')),
    CONSTRAINT chk_catalogue_option_prices_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_catalogue_option_prices_amount CHECK (amount_minor BETWEEN 0 AND 10000000000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Internal only (CAT-003): which adapter provisions the plan and with what
-- provider product (e.g. WHM package, Hetzner server type).
CREATE TABLE catalogue_provider_mappings (
    plan_id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    adapter VARCHAR(32) CHARACTER SET ascii NOT NULL,
    reference VARCHAR(128) CHARACTER SET ascii NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_catalogue_provider_mappings_plan FOREIGN KEY (plan_id) REFERENCES catalogue_plans (id),
    CONSTRAINT chk_catalogue_provider_mappings_adapter CHECK (REGEXP_LIKE(adapter, '^[a-z][a-z0-9_]{0,31}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Internal only (PRC-004): what the provider charges us, from a date.
CREATE TABLE catalogue_costs (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    plan_id CHAR(32) CHARACTER SET ascii NOT NULL,
    cycle VARCHAR(16) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    cost_minor BIGINT NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_catalogue_costs_point (plan_id, cycle, currency, effective_from),
    CONSTRAINT fk_catalogue_costs_cycle FOREIGN KEY (plan_id, cycle) REFERENCES catalogue_plan_cycles (plan_id, cycle),
    CONSTRAINT chk_catalogue_costs_currency CHECK (currency IN ('NGN', 'USD')),
    CONSTRAINT chk_catalogue_costs_amount CHECK (cost_minor BETWEEN 0 AND 10000000000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
