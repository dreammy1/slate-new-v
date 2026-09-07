-- SiteHub schema. All tables prefixed `sitehub_`.

CREATE TABLE IF NOT EXISTS `sitehub_sites` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(190) NOT NULL,
    `url`        VARCHAR(255) NOT NULL,
    `token`      TEXT NOT NULL,
    `status`     VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `version`    VARCHAR(30) NULL,
    `last_scan`  LONGTEXT NULL,
    `last_error` VARCHAR(255) NULL,
    `last_seen`  DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sitehub_runs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `site_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `action`     VARCHAR(40) NOT NULL,
    `ok`         TINYINT(1) NOT NULL DEFAULT 0,
    `detail`     TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_time` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sitehub_backups` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `site_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `filename`   VARCHAR(255) NOT NULL,
    `size`       INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_site` (`tenant_id`, `site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default-grant management to the Manager role (role_id = 2).
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`) VALUES (2, 'sitehub.view', 1);
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`) VALUES (2, 'sitehub.manage', 1);
