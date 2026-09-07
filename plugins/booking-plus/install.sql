-- Booking+ plugin — install schema.
--
-- Two tables that extend the core booking plugin without modifying its
-- schema. service_config carries per-service overrides (the practitioner
-- edits these once per service). appointment_meta carries per-booking
-- state (message thread, Zoom link override, therapist-reply tracking).
--
-- Both hang off booking's own tables by id; kept loose (no hard FK) so
-- the plugins stay independently installable and uninstalling booking
-- doesn't cascade-delete Booking+ history.

CREATE TABLE IF NOT EXISTS `bookingplus_service_config` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`             INT UNSIGNED NOT NULL,

    -- Gates enforced via the `booking_can_book` filter.
    `min_advance_days`       INT UNSIGNED NOT NULL DEFAULT 0,
    `prereq_service_id`      INT UNSIGNED NULL,
    `prereq_message`         VARCHAR(500) NULL,
    `hsr_redirect_service_id` INT UNSIGNED NULL,

    -- Links surfaced in the auto-response + reminders.
    `prep_page_url`          VARCHAR(500) NULL,
    `whatsapp_url`           VARCHAR(500) NULL,

    -- Immediate post-booking email (fires on `booking_created`).
    -- Placeholders: {{name}} {{service}} {{when}} {{ref}}
    --               {{prep_url}} {{whatsapp_url}} {{payment_note}}
    `auto_response_subject`  VARCHAR(200) NULL,
    `auto_response_body`     TEXT NULL,

    -- Per-service reminder overrides. Rendered by the companion cron
    -- and passed to booking via the `booking_reminder_body` filter (a
    -- 3-line patch to Booking core adds the filter — Path A of the
    -- spec). When NULL, booking's generic template is used unchanged.
    `reminder_8day_body`     TEXT NULL,
    `reminder_1day_body`     TEXT NULL,
    `reminder_10min_body`    TEXT NULL,

    -- Zoom handling.
    --   manual            → therapist pastes recurring room in zoom_join_url
    --   fallback_message  → client is told the link arrives by email
    --   api               → real Zoom OAuth (Phase 1.5, not wired yet)
    `zoom_mode`              ENUM('manual','fallback_message','api') NOT NULL DEFAULT 'fallback_message',
    `zoom_join_url`          VARCHAR(500) NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_service` (`tenant_id`, `service_id`),
    KEY `tenant_prereq` (`tenant_id`, `prereq_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Reserved time windows per service. Enforces "these slots are only bookable
-- for service X" rules — e.g. "Every Thursday 12:00-12:20 is Discovery Call
-- only". Enforced via the `booking_slot_allowed` filter in Booking core.
--
-- Semantics: for any candidate slot [start, end], if ANY row here overlaps it
-- on the same day_of_week (and matches the provider or is provider-agnostic),
-- the slot is HIDDEN unless one of those rows has service_id = the service
-- being booked. That is, a row "reserves" its window for its service.
CREATE TABLE IF NOT EXISTS `bookingplus_slot_restrictions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`  INT UNSIGNED NOT NULL,
    `provider_id` INT UNSIGNED NULL,           -- NULL = every provider
    `day_of_week` TINYINT NOT NULL,             -- 0=Sunday, 6=Saturday
    `start_time`  TIME NOT NULL,
    `end_time`    TIME NOT NULL,
    `label`       VARCHAR(80) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_day` (`tenant_id`, `day_of_week`),
    KEY `tenant_service` (`tenant_id`, `service_id`),
    KEY `tenant_provider` (`tenant_id`, `provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `bookingplus_appointment_meta` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `appointment_id`         INT UNSIGNED NOT NULL,

    -- Per-appointment Zoom override (wins over service default).
    `zoom_join_url`          VARCHAR(500) NULL,
    `zoom_link_sent_at`      DATETIME NULL,

    -- Free-text human-message step (§2.5 of the spec).
    `client_message`         TEXT NULL,
    `client_message_at`      DATETIME NULL,

    -- Therapist notification + 8-hour internal nudge tracking (§2.6).
    `therapist_notified_at`  DATETIME NULL,
    `therapist_replied_at`   DATETIME NULL,
    `nudge_sent_at`          DATETIME NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_appt` (`tenant_id`, `appointment_id`),
    KEY `nudge_scan` (`tenant_id`, `therapist_replied_at`, `nudge_sent_at`, `therapist_notified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
