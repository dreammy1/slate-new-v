-- Client Desk 2.1.0 — portal landing page "Request access" submissions.

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
