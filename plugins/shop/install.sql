-- Shop plugin schema.
-- All tables are prefixed `shop_` (slug + underscore).

-- ──────────────────────────────────────────────────────────
-- Products
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_products` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `sku`          VARCHAR(100) NOT NULL DEFAULT '',
    `name`         VARCHAR(255) NOT NULL,
    `slug`         VARCHAR(255) NOT NULL DEFAULT '',
    `description`  TEXT NULL,
    `short_desc`   VARCHAR(500) NULL,
    `price`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `sale_price`   DECIMAL(12,2) NULL,
    `cost_price`   DECIMAL(12,2) NULL,
    `stock_qty`    INT NOT NULL DEFAULT 0,
    `manage_stock` TINYINT(1) NOT NULL DEFAULT 1,
    `weight`       DECIMAL(8,3) NULL,
    `category`     VARCHAR(100) NULL,
    `tags`         VARCHAR(500) NULL,
    `image_url`    VARCHAR(500) NULL,
    `status`       ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_sku` (`tenant_id`, `sku`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_category` (`tenant_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────
-- Customers
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_customers` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `email`        VARCHAR(190) NOT NULL,
    `first_name`   VARCHAR(100) NOT NULL DEFAULT '',
    `last_name`    VARCHAR(100) NOT NULL DEFAULT '',
    `phone`        VARCHAR(50)  NULL,
    `address_1`    VARCHAR(255) NULL,
    `address_2`    VARCHAR(255) NULL,
    `city`         VARCHAR(100) NULL,
    `state`        VARCHAR(100) NULL,
    `postcode`     VARCHAR(30)  NULL,
    `country`      VARCHAR(2)   NULL,
    `notes`        TEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_email` (`tenant_id`, `email`),
    KEY `tenant_name` (`tenant_id`, `last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────
-- Orders
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_orders` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `order_number`    VARCHAR(30)  NOT NULL,
    -- Unguessable capability key used in the /shop/order?key=... URL.
    -- Distinct from order_number (which is sequential and shown to
    -- the customer) so the confirmation URL can't be enumerated.
    `view_token`      CHAR(32)     NULL,
    `customer_id`     INT UNSIGNED NULL,
    `customer_email`  VARCHAR(190) NOT NULL DEFAULT '',
    `customer_name`   VARCHAR(255) NOT NULL DEFAULT '',
    `status`          ENUM('pending','processing','on-hold','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_total`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `shipping_total`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_total`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`        VARCHAR(3)   NOT NULL DEFAULT 'USD',
    `payment_method`  VARCHAR(100) NULL,
    `coupon_code`     VARCHAR(100) NULL,
    `notes`           TEXT NULL,
    `ship_address_1`  VARCHAR(255) NULL,
    `ship_address_2`  VARCHAR(255) NULL,
    `ship_city`       VARCHAR(100) NULL,
    `ship_state`      VARCHAR(100) NULL,
    `ship_postcode`   VARCHAR(30)  NULL,
    `ship_country`    VARCHAR(2)   NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_order_number` (`tenant_id`, `order_number`),
    KEY `view_token_idx` (`view_token`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────
-- Order line items
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_order_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`    INT UNSIGNED NOT NULL,
    `product_id`  INT UNSIGNED NULL,
    `product_sku` VARCHAR(100) NOT NULL DEFAULT '',
    `product_name`VARCHAR(255) NOT NULL DEFAULT '',
    `quantity`    INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `line_total`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────
-- Coupons
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_coupons` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `code`           VARCHAR(100) NOT NULL,
    `type`           ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `value`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `min_order`      DECIMAL(12,2) NULL,
    `max_uses`       INT UNSIGNED NULL,
    `uses`           INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`     DATETIME NULL,
    `active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default permission grants (role_id=2 = Manager)
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`)
VALUES
    (2, 'shop.view_orders',     1),
    (2, 'shop.manage_orders',   1),
    (2, 'shop.manage_products', 1),
    (2, 'shop.view_reports',    1);
