-- Flat Rate Shipping — schema (v1.0.0).
--
-- One table of admin-editable box presets. Each box has a slug used
-- to reference it from products, a display name, a price, and a
-- "capacity" — the approximate number of average-sized items the box
-- can hold. Capacity drives the greedy bin-packing algorithm in
-- ShippingFlatRate::packCart().
--
-- We seed it with the four current USPS Flat Rate options as a
-- starting point. The admin can edit prices when USPS changes them
-- (USPS typically bumps rates once per year in January).

CREATE TABLE IF NOT EXISTS shippingflatrate_boxes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(64)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    capacity    INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 100,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug),
    KEY idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
