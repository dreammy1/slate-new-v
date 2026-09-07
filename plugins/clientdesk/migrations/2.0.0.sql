-- Client Desk 2.0.0 — quotes/proposals, file attachments, project
-- comments, and reusable project templates. New tables only; additive
-- columns are handled idempotently in Clientdesk::runMigrations().

-- Quotes / proposals the client approves online before work starts.
CREATE TABLE IF NOT EXISTS `clientdesk_quotes` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `client_id`    INT UNSIGNED NOT NULL,
    `project_id`   INT UNSIGNED NULL,
    `number`       VARCHAR(40) NOT NULL,
    `title`        VARCHAR(190) NOT NULL,
    `currency`     CHAR(3) NOT NULL DEFAULT 'USD',
    `line_items`   MEDIUMTEXT NULL,
    `total_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `body`         MEDIUMTEXT NULL,
    `status`       ENUM('draft','sent','approved','declined','expired') NOT NULL DEFAULT 'draft',
    `valid_until`  DATE NULL,
    `sent_at`      DATETIME NULL,
    `decided_at`   DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_number` (`tenant_id`, `number`),
    KEY `tenant_client` (`tenant_id`, `client_id`),
    KEY `tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File attachments / deliverables. Scoped to a project; visible_to_client
-- gates whether the portal shows it (brief assets vs. internal notes).
CREATE TABLE IF NOT EXISTS `clientdesk_files` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`       INT UNSIGNED NOT NULL,
    `label`            VARCHAR(190) NULL,
    `path`             VARCHAR(255) NOT NULL,
    `original`         VARCHAR(190) NULL,
    `mime`             VARCHAR(100) NULL,
    `size_bytes`       INT UNSIGNED NOT NULL DEFAULT 0,
    `kind`             ENUM('asset','deliverable') NOT NULL DEFAULT 'asset',
    `uploaded_by`      ENUM('staff','client') NOT NULL DEFAULT 'staff',
    `visible_to_client` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_project` (`tenant_id`, `project_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-way project comments (distinct from the system activity feed).
CREATE TABLE IF NOT EXISTS `clientdesk_comments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`   INT UNSIGNED NOT NULL,
    `author_type`  ENUM('staff','client') NOT NULL DEFAULT 'staff',
    `author_name`  VARCHAR(120) NULL,
    `body`         TEXT NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_project_time` (`tenant_id`, `project_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable milestone templates per project type, seeded with sensible
-- defaults for a freelance web build.
CREATE TABLE IF NOT EXISTS `clientdesk_templates` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `name`         VARCHAR(120) NOT NULL,
    `milestones`   MEDIUMTEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
