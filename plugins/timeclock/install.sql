-- Site Timeclock — install schema (idempotent)

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

-- Open shifts (clocked in, not yet clocked out). One active row per employee.
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

-- Completed entries.
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

-- Seed default tasks (only if the table is empty is hard to express; rely on
-- INSERT-once via a guard name. These are safe to re-run because of the slug
-- owning the table; duplicates are harmless but we avoid them with NOT EXISTS.)
INSERT INTO `timeclock_tasks` (`tenant_id`, `name`, `color`)
SELECT 1, 'General Labour', '#2563EB' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `timeclock_tasks` WHERE `tenant_id` = 1 AND `name` = 'General Labour');

INSERT INTO `timeclock_tasks` (`tenant_id`, `name`, `color`)
SELECT 1, 'Concrete', '#F59E0B' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `timeclock_tasks` WHERE `tenant_id` = 1 AND `name` = 'Concrete');

INSERT INTO `timeclock_tasks` (`tenant_id`, `name`, `color`)
SELECT 1, 'Framing', '#10B981' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `timeclock_tasks` WHERE `tenant_id` = 1 AND `name` = 'Framing');

INSERT INTO `timeclock_tasks` (`tenant_id`, `name`, `color`)
SELECT 1, 'Cleanup', '#6B7280' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `timeclock_tasks` WHERE `tenant_id` = 1 AND `name` = 'Cleanup');

-- Default permission grants for the Manager role (role_id 2).
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`) VALUES
    (2, 'timeclock.view',   1),
    (2, 'timeclock.manage', 1);
