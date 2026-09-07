-- React Site Bridge plugin schema. All tables use the reactsitebridge_ prefix.

CREATE TABLE IF NOT EXISTS `reactsitebridge_sites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_key` VARCHAR(80) NOT NULL,
  `public_id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `public_origin` VARCHAR(255) NULL,
  `preview_origin` VARCHAR(255) NULL,
  `active_revision_id` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_site_key` (`tenant_id`,`site_key`),
  UNIQUE KEY `public_id` (`public_id`),
  KEY `tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactsitebridge_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_id` INT UNSIGNED NOT NULL,
  `route` VARCHAR(255) NOT NULL,
  `locale` VARCHAR(16) NOT NULL DEFAULT 'en',
  `schema_key` VARCHAR(120) NOT NULL DEFAULT '',
  `document_json` JSON NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_route_locale` (`site_id`,`route`,`locale`),
  KEY `tenant_site` (`tenant_id`,`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactsitebridge_media` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_id` INT UNSIGNED NOT NULL,
  `logical_key` VARCHAR(190) NOT NULL,
  `media_id` INT UNSIGNED NULL,
  `alt_text` VARCHAR(500) NOT NULL DEFAULT '',
  `focal_x` DECIMAL(5,4) NULL,
  `focal_y` DECIMAL(5,4) NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_logical_key` (`site_id`,`logical_key`),
  KEY `tenant_site` (`tenant_id`,`site_id`),
  KEY `media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactsitebridge_revisions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_id` INT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `manifest_json` JSON NOT NULL,
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `published_by` INT UNSIGNED NULL,
  `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_version` (`site_id`,`version`),
  KEY `tenant_site_time` (`tenant_id`,`site_id`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactsitebridge_hosted_releases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_id` INT UNSIGNED NOT NULL,
  `storage_path` VARCHAR(255) NOT NULL,
  `entrypoint` VARCHAR(120) NOT NULL DEFAULT 'index.html',
  `file_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_site` (`tenant_id`,`site_id`),
  KEY `site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactsitebridge_content_sources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `site_id` INT UNSIGNED NOT NULL,
  `source_key` VARCHAR(80) NOT NULL,
  `content_type` VARCHAR(80) NOT NULL DEFAULT 'kaimana-editorial',
  `item_limit` TINYINT UNSIGNED NOT NULL DEFAULT 12,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_site_source` (`tenant_id`,`site_id`,`source_key`),
  KEY `tenant_type_enabled` (`tenant_id`,`content_type`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
