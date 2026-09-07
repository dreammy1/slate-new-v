-- Flat-rate shipping plugin schema.

CREATE TABLE IF NOT EXISTS `flatrateshipping_tiers` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `label`         VARCHAR(100) NOT NULL DEFAULT '',
    `min_weight`    DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    -- NULL on max_weight means "no upper bound" (i.e., the overflow tier).
    -- Stored as DECIMAL so the same unit works for oz and kg without
    -- conversion (admin picks one weight_unit setting and sticks to it).
    `max_weight`    DECIMAL(10,3) NULL,
    `rate`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `display_order` INT NOT NULL DEFAULT 0,
    `active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `active`, `min_weight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
