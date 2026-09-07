-- Site Timeclock 1.0.0 — schema for upgrades from a pre-release install.
-- Mirrors install.sql table definitions (idempotent).

CREATE TABLE IF NOT EXISTS `timeclock_employees` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(120) NOT NULL,
    `color`      VARCHAR(7)   NOT NULL DEFAULT '#2563EB',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timeclock_sites` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timeclock_tasks` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(120) NOT NULL,
    `color`      VARCHAR(7)   NOT NULL DEFAULT '#10B981',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timeclock_active` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `employee_id` INT UNSIGNED NOT NULL,
    `site_id`     INT UNSIGNED NOT NULL,
    `work_date`   DATE NOT NULL,
    `clock_in`    VARCHAR(5) NOT NULL,
    `started_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp` (`tenant_id`, `employee_id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timeclock_entries` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `employee_id` INT UNSIGNED NOT NULL,
    `site_id`     INT UNSIGNED NOT NULL,
    `work_date`   DATE NOT NULL,
    `clock_in`    VARCHAR(5) NOT NULL,
    `clock_out`   VARCHAR(5) NOT NULL,
    `total_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `tasks_json`  TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`),
    KEY `idx_date` (`tenant_id`, `work_date`),
    KEY `idx_emp` (`tenant_id`, `employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
