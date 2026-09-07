-- Content Builder schema.
-- All tables prefixed with "contentbuilder_" (slug with hyphens removed + underscore).

CREATE TABLE IF NOT EXISTS `contentbuilder_post_types` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `slug`          VARCHAR(64)  NOT NULL,
    `label`         VARCHAR(128) NOT NULL,
    `singular`      VARCHAR(128) NOT NULL,
    `is_builtin`    TINYINT(1)   NOT NULL DEFAULT 0,
    `hierarchical`  TINYINT(1)   NOT NULL DEFAULT 0,
    `supports`      JSON         NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`,`slug`),
    KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contentbuilder_posts` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `type`          VARCHAR(64)  NOT NULL,
    `parent_id`     INT UNSIGNED NULL,
    `title`         VARCHAR(255) NOT NULL DEFAULT '',
    `slug`          VARCHAR(255) NOT NULL,
    `status`        ENUM('draft','published','trash') NOT NULL DEFAULT 'draft',
    `layout`        JSON         NULL,
    `excerpt`       TEXT         NULL,
    `author_id`     INT UNSIGNED NULL,
    `published_at`  DATETIME     NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_type_slug` (`tenant_id`,`type`,`slug`),
    KEY `tenant_status` (`tenant_id`,`status`),
    KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contentbuilder_post_meta` (
    `post_id`  INT UNSIGNED NOT NULL,
    `meta_key` VARCHAR(128) NOT NULL,
    `meta_val` TEXT NULL,
    PRIMARY KEY (`post_id`, `meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contentbuilder_taxonomies` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `slug`          VARCHAR(64)  NOT NULL,
    `label`         VARCHAR(128) NOT NULL,
    `hierarchical`  TINYINT(1)   NOT NULL DEFAULT 0,
    `post_types`    JSON         NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`,`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contentbuilder_terms` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `taxonomy`      VARCHAR(64)  NOT NULL,
    `parent_id`     INT UNSIGNED NULL,
    `name`          VARCHAR(128) NOT NULL,
    `slug`          VARCHAR(128) NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_tax_slug` (`tenant_id`,`taxonomy`,`slug`),
    KEY `taxonomy` (`taxonomy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contentbuilder_term_relations` (
    `post_id`  INT UNSIGNED NOT NULL,
    `term_id`  INT UNSIGNED NOT NULL,
    PRIMARY KEY (`post_id`,`term_id`),
    KEY `term_id` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Navigation menus. A menu is a named, ordered set of links stored as JSON.
-- location: 'header' | 'footer' | '' (unassigned). One menu per location.
CREATE TABLE IF NOT EXISTS `contentbuilder_menus` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(128) NOT NULL,
    `location`   VARCHAR(32)  NOT NULL DEFAULT '',
    `items`      JSON         NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_loc` (`tenant_id`,`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `contentbuilder_post_types`
    (tenant_id, slug, label, singular, is_builtin, hierarchical, supports)
VALUES
    (1,'page','Pages','Page',1,1,'["title","editor"]'),
    (1,'post','Posts','Post',1,0,'["title","editor","excerpt","taxonomy"]');

INSERT IGNORE INTO `contentbuilder_taxonomies`
    (tenant_id, slug, label, hierarchical, post_types)
VALUES
    (1,'category','Categories',1,'["post"]'),
    (1,'tag','Tags',0,'["post"]');

-- Seed a published home page so /p/home works immediately after activation.
INSERT IGNORE INTO `contentbuilder_posts`
    (tenant_id, type, title, slug, status, layout, published_at)
VALUES
    (1, 'page', 'Home', 'home', 'published',
     '[{"type":"heading","props":{"text":"Welcome","level":"1"}},{"type":"paragraph","props":{"text":"This is your new home page. Edit it under Pages, or build new pages from templates."}}]',
     NOW());
