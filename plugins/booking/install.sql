-- Booking plugin — install schema (full, as of 0.2.0).
-- Fresh installs get every table + column here. Existing installs are
-- brought up to date by version-gated migrations in Booking.php.

CREATE TABLE IF NOT EXISTS `booking_services` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `name`             VARCHAR(160) NOT NULL,
    `slug`             VARCHAR(80) NOT NULL,
    `description`      TEXT NULL,
    `confirm_subject`  VARCHAR(200) NULL,
    `confirm_body`     TEXT NULL,
    `category_id`      INT UNSIGNED NULL,
    `location_id`      INT UNSIGNED NULL,
    `duration_min`     INT UNSIGNED NOT NULL DEFAULT 30,
    `duration_max_min` INT UNSIGNED NULL,
    `slot_interval_min` INT UNSIGNED NOT NULL DEFAULT 0,
    `buffer_before_min` INT UNSIGNED NOT NULL DEFAULT 0,
    `buffer_min`       INT UNSIGNED NOT NULL DEFAULT 0,
    `capacity`         INT UNSIGNED NOT NULL DEFAULT 1,
    `min_advance_min`  INT UNSIGNED NOT NULL DEFAULT 0,
    `max_advance_days` INT UNSIGNED NOT NULL DEFAULT 365,
    `is_online`        TINYINT(1) NOT NULL DEFAULT 0,
    `requires_resource` TINYINT(1) NOT NULL DEFAULT 0,
    `price_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`         VARCHAR(8) NOT NULL DEFAULT 'USD',
    `payment_mode`     ENUM('free','full','deposit','onsite') NOT NULL DEFAULT 'free',
    `deposit_type`     ENUM('fixed','percent') NOT NULL DEFAULT 'percent',
    `deposit_value`    INT UNSIGNED NOT NULL DEFAULT 0,
    `tax_rate`         DECIMAL(6,3) NOT NULL DEFAULT 0,
    `color`            VARCHAR(16) NOT NULL DEFAULT '#2563EB',
    `sort_order`       INT NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_active` (`tenant_id`, `is_active`),
    KEY `tenant_category` (`tenant_id`, `category_id`),
    KEY `tenant_sort` (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_providers` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `user_id`        INT UNSIGNED NULL,
    `name`           VARCHAR(160) NOT NULL,
    `email`          VARCHAR(200) NULL,
    `phone`          VARCHAR(40) NULL,
    `timezone`       VARCHAR(60) NOT NULL DEFAULT 'UTC',
    `bio`            TEXT NULL,
    `color`          VARCHAR(16) NOT NULL DEFAULT '#2563EB',
    `sort_order`     INT NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A provider performs zero-or-more services. Many-to-many, with optional
-- per-staff price/duration overrides.
CREATE TABLE IF NOT EXISTS `booking_provider_services` (
    `provider_id`  INT UNSIGNED NOT NULL,
    `service_id`   INT UNSIGNED NOT NULL,
    `price_cents`  INT UNSIGNED NULL,
    `duration_min` INT UNSIGNED NULL,
    PRIMARY KEY (`provider_id`, `service_id`),
    KEY `service_idx` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring weekly hours: one row per (provider, day_of_week) block.
-- day_of_week is 0=Sunday … 6=Saturday (matches PHP date('w')).
CREATE TABLE IF NOT EXISTS `booking_provider_hours` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id`    INT UNSIGNED NOT NULL,
    `day_of_week`    TINYINT NOT NULL,
    `start_time`     TIME NOT NULL,
    `end_time`       TIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `provider_day` (`provider_id`, `day_of_week`),
    KEY `tenant_provider` (`tenant_id`, `provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_appointments` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `ref`            VARCHAR(32) NOT NULL,
    `manage_token`   CHAR(32) NULL,
    `service_id`     INT UNSIGNED NOT NULL,
    `provider_id`    INT UNSIGNED NOT NULL,
    `location_id`    INT UNSIGNED NULL,
    `resource_id`    INT UNSIGNED NULL,
    `customer_id`    INT UNSIGNED NULL,
    `customer_name`  VARCHAR(160) NOT NULL,
    `customer_email` VARCHAR(200) NOT NULL,
    `customer_phone` VARCHAR(40) NULL,
    `party_size`     INT UNSIGNED NOT NULL DEFAULT 1,
    `notes`          TEXT NULL,
    `addons_json`    TEXT NULL,
    `custom_json`    TEXT NULL,
    `starts_at`      DATETIME NOT NULL,
    `ends_at`        DATETIME NOT NULL,
    `status`         ENUM('pending','awaiting_approval','confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed',
    `source`         ENUM('online','walkin','admin') NOT NULL DEFAULT 'online',
    `recurrence_group` VARCHAR(32) NULL,
    `price_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `tax_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `deposit_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `paid_cents`     INT UNSIGNED NOT NULL DEFAULT 0,
    `payment_status` ENUM('none','pending','deposit_paid','paid','refunded','partially_refunded','failed') NOT NULL DEFAULT 'none',
    `payment_ref`    VARCHAR(120) NULL,
    `reschedule_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `cancel_reason`  VARCHAR(255) NULL,
    `cancelled_at`   DATETIME NULL,
    `reminder_24h_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `reminder_1h_sent`  TINYINT(1) NOT NULL DEFAULT 0,
    `reminders_sent`    VARCHAR(160) NULL,
    `followup_sent`     TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_ref` (`tenant_id`, `ref`),
    KEY `tenant_status_starts` (`tenant_id`, `status`, `starts_at`),
    KEY `provider_slot` (`provider_id`, `starts_at`, `ends_at`),
    KEY `resource_slot` (`resource_id`, `starts_at`, `ends_at`),
    KEY `customer_starts` (`customer_id`, `starts_at`),
    KEY `recurrence_group` (`recurrence_group`),
    KEY `manage_token_idx` (`manage_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_customers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`     INT UNSIGNED NULL,
    `email`           VARCHAR(200) NOT NULL,
    `name`            VARCHAR(160) NOT NULL,
    `phone`           VARCHAR(40) NULL,
    `birthday`        DATE NULL,
    `notes`           TEXT NULL,
    `tags`            VARCHAR(255) NULL,
    `no_show_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `completed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `booking_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `loyalty_points`  INT NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_email` (`tenant_id`, `email`),
    KEY `tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `slug`       VARCHAR(80) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_sort` (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_locations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `type`       ENUM('in_person','online') NOT NULL DEFAULT 'in_person',
    `address`    TEXT NULL,
    `meeting_url` VARCHAR(500) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_resources` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `capacity`   INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_service_resources` (
    `service_id`  INT UNSIGNED NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`service_id`, `resource_id`),
    KEY `resource_idx` (`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_service_addons` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`   INT UNSIGNED NOT NULL,
    `name`         VARCHAR(160) NOT NULL,
    `price_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_min` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_service` (`tenant_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_custom_fields` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`   INT UNSIGNED NULL,
    `label`        VARCHAR(200) NOT NULL,
    `name`         VARCHAR(80) NOT NULL,
    `type`         ENUM('text','textarea','select','checkbox','file') NOT NULL DEFAULT 'text',
    `options_json` TEXT NULL,
    `is_required`  TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_service` (`tenant_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_provider_breaks` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id` INT UNSIGNED NOT NULL,
    `day_of_week` TINYINT NOT NULL,
    `start_time`  TIME NOT NULL,
    `end_time`    TIME NOT NULL,
    `label`       VARCHAR(80) NULL,
    PRIMARY KEY (`id`),
    KEY `provider_day` (`provider_id`, `day_of_week`),
    KEY `tenant_provider` (`tenant_id`, `provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_date_overrides` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id` INT UNSIGNED NULL,
    `date`        DATE NOT NULL,
    `is_closed`   TINYINT(1) NOT NULL DEFAULT 1,
    `start_time`  TIME NULL,
    `end_time`    TIME NULL,
    `note`        VARCHAR(160) NULL,
    PRIMARY KEY (`id`),
    KEY `tenant_date` (`tenant_id`, `date`),
    KEY `provider_date` (`provider_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
