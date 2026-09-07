-- Shop v1.1.0 — schema additions
-- This file is split by `;` and executed statement-by-statement. Keep
-- it free of stored-procedure tricks or multi-statement constructs.
-- Idempotency for the ALTER TABLE statements is handled in PHP via
-- Shop::runMigrations() which checks INFORMATION_SCHEMA before each one.

CREATE TABLE IF NOT EXISTS `shop_categories` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `slug`          VARCHAR(100) NOT NULL,
    `name`          VARCHAR(100) NOT NULL,
    `description`   TEXT NULL,
    `image_url`     VARCHAR(500) NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_product_variants` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`    INT UNSIGNED NOT NULL,
    `attribute`     VARCHAR(50)  NOT NULL,
    `value`         VARCHAR(100) NOT NULL,
    `sku`           VARCHAR(100) NOT NULL DEFAULT '',
    `price_diff`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `stock_qty`     INT NOT NULL DEFAULT 0,
    `display_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `product_id` (`product_id`),
    UNIQUE KEY `product_value` (`product_id`, `attribute`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_carts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `session_id` VARCHAR(128) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_session` (`tenant_id`, `session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_cart_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cart_id`    INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `qty`        INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `added_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `cart_id` (`cart_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO shop_categories (tenant_id, slug, name, description, display_order) VALUES
    (1, 'uncategorized', 'Uncategorized', 'Default catch-all category.', 999);
