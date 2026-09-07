-- Client Desk schema.
-- All tables prefixed `clientdesk_` (slug + underscore). Idempotent.

-- Clients. Links to a core `customers` row (customer_id) for portal login.
-- `source` separates direct vs Fiverr vs referral clients.
CREATE TABLE IF NOT EXISTS `clientdesk_clients` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`  INT UNSIGNED NULL,
    `name`         VARCHAR(160) NOT NULL,
    `company`      VARCHAR(160) NULL,
    `email`        VARCHAR(190) NULL,
    `phone`        VARCHAR(40)  NULL,
    `source`       ENUM('direct','fiverr','referral','other') NOT NULL DEFAULT 'direct',
    `source_ref`   VARCHAR(120) NULL,
    `status`       ENUM('active','completed','on_hold','archived') NOT NULL DEFAULT 'active',
    `access_token` CHAR(40) NULL,
    `tags`         VARCHAR(255) NULL,
    `notes`        TEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_token` (`tenant_id`, `access_token`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_source` (`tenant_id`, `source`),
    KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projects belonging to a client.
CREATE TABLE IF NOT EXISTS `clientdesk_projects` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `client_id`    INT UNSIGNED NOT NULL,
    `title`        VARCHAR(190) NOT NULL,
    `project_type` VARCHAR(80)  NULL,
    `phase`        ENUM('onboarding','design','development','review','revisions','launch','complete')
                       NOT NULL DEFAULT 'onboarding',
    `progress`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `staging_url`  VARCHAR(255) NULL,
    `deadline`     DATE NULL,
    `agreement`    MEDIUMTEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_client` (`tenant_id`, `client_id`),
    KEY `tenant_phase` (`tenant_id`, `phase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-project milestones shown on the progress dashboard.
CREATE TABLE IF NOT EXISTS `clientdesk_milestones` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`   INT UNSIGNED NOT NULL,
    `label`        VARCHAR(190) NOT NULL,
    `done`         TINYINT(1) NOT NULL DEFAULT 0,
    `done_at`      DATETIME NULL,
    `sort_order`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_project` (`tenant_id`, `project_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity feed entries (status changes, notes) shown to the client.
CREATE TABLE IF NOT EXISTS `clientdesk_activity` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`   INT UNSIGNED NOT NULL,
    `body`         VARCHAR(500) NOT NULL,
    `author`       VARCHAR(120) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_project_time` (`tenant_id`, `project_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Onboarding questionnaire answers (one row per project). JSON payload
-- keeps the form flexible without schema churn.
CREATE TABLE IF NOT EXISTS `clientdesk_intake` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`   INT UNSIGNED NOT NULL,
    `answers`      MEDIUMTEXT NULL,
    `approved`     TINYINT(1) NOT NULL DEFAULT 0,
    `submitted_at` DATETIME NULL,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_project` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team assignments: maps a core admin user to a project.
CREATE TABLE IF NOT EXISTS `clientdesk_assignments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `project_id`   INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `role_label`   VARCHAR(80) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_project_user` (`tenant_id`, `project_id`, `user_id`),
    KEY `tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices. `requirements_brief` + `agreement_copy` are attached snapshots.
CREATE TABLE IF NOT EXISTS `clientdesk_invoices` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `client_id`         INT UNSIGNED NOT NULL,
    `project_id`        INT UNSIGNED NULL,
    `number`            VARCHAR(40) NOT NULL,
    `currency`          CHAR(3) NOT NULL DEFAULT 'USD',
    `line_items`        MEDIUMTEXT NULL,
    `total_cents`       INT UNSIGNED NOT NULL DEFAULT 0,
    `status`            ENUM('draft','sent','paid','overdue') NOT NULL DEFAULT 'draft',
    `agreement_copy`    MEDIUMTEXT NULL,
    `requirements_brief` MEDIUMTEXT NULL,
    `due_date`          DATE NULL,
    `sent_at`           DATETIME NULL,
    `paid_at`           DATETIME NULL,
    `payment_ref`       VARCHAR(120) NULL,
    `payment_method`    VARCHAR(40) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_number` (`tenant_id`, `number`),
    KEY `tenant_client` (`tenant_id`, `client_id`),
    KEY `tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Support tickets raised by clients from their portal.
CREATE TABLE IF NOT EXISTS `clientdesk_tickets` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `client_id`    INT UNSIGNED NOT NULL,
    `project_id`   INT UNSIGNED NULL,
    `subject`      VARCHAR(190) NOT NULL,
    `priority`     ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    `status`       ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_client` (`tenant_id`, `client_id`),
    KEY `tenant_status` (`tenant_id`, `status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Threaded ticket replies. author_type tells client vs staff apart.
CREATE TABLE IF NOT EXISTS `clientdesk_ticket_messages` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `ticket_id`    INT UNSIGNED NOT NULL,
    `author_type`  ENUM('client','staff') NOT NULL DEFAULT 'client',
    `author_name`  VARCHAR(120) NULL,
    `body`         TEXT NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_ticket_time` (`tenant_id`, `ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `clientdesk_templates` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `name`         VARCHAR(120) NOT NULL,
    `milestones`   MEDIUMTEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clientdesk_access_requests` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `email`      VARCHAR(190) NOT NULL,
    `message`    TEXT NULL,
    `status`     ENUM('new','handled','dismissed') NOT NULL DEFAULT 'new',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_status` (`tenant_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default grant: give the Manager role (role_id=2) the everyday perms.
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`) VALUES
    (2, 'clientdesk.view',            1),
    (2, 'clientdesk.manage_clients',  1),
    (2, 'clientdesk.manage_projects', 1),
    (2, 'clientdesk.manage_invoices', 1),
    (2, 'clientdesk.manage_team',     1),
    (2, 'clientdesk.handle_support',  1),
    (2, 'clientdesk.manage_quotes',   1);

-- Seed a default milestone template for a typical freelance web build.
INSERT INTO `clientdesk_templates` (`tenant_id`, `name`, `milestones`)
SELECT 1, 'Standard website build',
    '["Kickoff & questionnaire","Sitemap & wireframes","Design mockups approved","Development","Content loaded","Client review","Revisions","SEO & performance pass","Launch"]'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `clientdesk_templates` WHERE `tenant_id` = 1 AND `name` = 'Standard website build');
