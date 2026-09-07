<?php
/**
 * Booking+ — public API.
 *
 * All DB access + template rendering lives here so the bootstrap class
 * stays thin and other plugins can call BookingPlusAPI::* without
 * touching internals.
 *
 * Nothing here references booking's internals directly — we go through
 * BookingAPI's public methods when we need service/appointment data.
 */

class BookingPlusAPI {

    // ── Schema self-heal ─────────────────────────────────────────────────
    // Idempotent (CREATE TABLE IF NOT EXISTS) — safe to replay. The
    // bootstrap stamps the plugin version once so we skip on the hot path.
    public static function ensureSchema(): void {
        $sql = @file_get_contents(__DIR__ . '/install.sql');
        if ($sql === false || $sql === '') return;
        foreach (self::splitSqlStatements($sql) as $stmt) {
            try { Database::query($stmt); }
            catch (\Throwable $e) {
                slate_log('BookingPlus schema replay failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    private static function splitSqlStatements(string $sql): array {
        $out = [];
        foreach (preg_split("/;\s*[\r\n]/", $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '' && stripos($stmt, 'CREATE') !== false) $out[] = $stmt;
        }
        return $out;
    }

    // ── Service config CRUD ──────────────────────────────────────────────

    /** Fetch the extras row for a service, or an empty defaults array. */
    public static function getServiceConfig(int $serviceId): array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM bookingplus_service_config
              WHERE tenant_id = ? AND service_id = ? LIMIT 1",
            [$tid, $serviceId]
        );
        return $row ?: self::defaultServiceConfig($serviceId);
    }

    public static function defaultServiceConfig(int $serviceId): array {
        return [
            'id'                      => 0,
            'tenant_id'               => current_tenant_id(),
            'service_id'              => $serviceId,
            'min_advance_days'        => 0,
            'prereq_service_id'       => null,
            'prereq_message'          => null,
            'hsr_redirect_service_id' => null,
            'prep_page_url'           => null,
            'whatsapp_url'            => null,
            'auto_response_subject'   => null,
            'auto_response_body'      => null,
            'reminder_8day_body'      => null,
            'reminder_1day_body'      => null,
            'reminder_10min_body'     => null,
            'zoom_mode'               => 'fallback_message',
            'zoom_join_url'           => null,
        ];
    }

    /** Upsert a service config row. Returns the row's id. */
    public static function saveServiceConfig(int $serviceId, array $fields): int {
        $tid = current_tenant_id();
        $allowed = [
            'min_advance_days', 'prereq_service_id', 'prereq_message',
            'hsr_redirect_service_id', 'prep_page_url', 'whatsapp_url',
            'auto_response_subject', 'auto_response_body',
            'reminder_8day_body', 'reminder_1day_body', 'reminder_10min_body',
            'zoom_mode', 'zoom_join_url',
        ];
        $data = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) $data[$k] = $fields[$k];
        }
        if (isset($data['zoom_mode']) && !in_array($data['zoom_mode'], ['manual','fallback_message','api'], true)) {
            $data['zoom_mode'] = 'fallback_message';
        }

        $existing = Database::row(
            "SELECT id FROM bookingplus_service_config WHERE tenant_id = ? AND service_id = ?",
            [$tid, $serviceId]
        );
        if ($existing) {
            Database::update('bookingplus_service_config', $data, 'id = ?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        $data['tenant_id']  = $tid;
        $data['service_id'] = $serviceId;
        return (int) Database::insert('bookingplus_service_config', $data);
    }

    // ── Appointment meta CRUD ────────────────────────────────────────────

    public static function getAppointmentMeta(int $apptId): array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM bookingplus_appointment_meta
              WHERE tenant_id = ? AND appointment_id = ? LIMIT 1",
            [$tid, $apptId]
        );
        return $row ?: [
            'id'                    => 0,
            'appointment_id'        => $apptId,
            'zoom_join_url'         => null,
            'zoom_link_sent_at'     => null,
            'client_message'        => null,
            'client_message_at'    => null,
            'therapist_notified_at' => null,
            'therapist_replied_at'  => null,
            'nudge_sent_at'         => null,
        ];
    }

    public static function saveAppointmentMeta(int $apptId, array $fields): int {
        $tid = current_tenant_id();
        $allowed = [
            'zoom_join_url', 'zoom_link_sent_at',
            'client_message', 'client_message_at',
            'therapist_notified_at', 'therapist_replied_at', 'nudge_sent_at',
        ];
        $data = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) $data[$k] = $fields[$k];
        }
        $existing = Database::row(
            "SELECT id FROM bookingplus_appointment_meta WHERE tenant_id = ? AND appointment_id = ?",
            [$tid, $apptId]
        );
        if ($existing) {
            Database::update('bookingplus_appointment_meta', $data, 'id = ?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        $data['tenant_id']      = $tid;
        $data['appointment_id'] = $apptId;
        return (int) Database::insert('bookingplus_appointment_meta', $data);
    }

    // ── Template rendering ───────────────────────────────────────────────

    /**
     * Substitute {{placeholders}} in a Booking+ template. Extends the
     * placeholder set BookingAPI::renderTemplate() supports.
     *
     * $ctx keys used:
     *   customer_name, service_name, provider_name, starts_at, ref,
     *   prep_url, whatsapp_url, payment_note, zoom_url, payment_link
     */
    public static function renderTemplate(string $tpl, array $ctx): string {
        $ts = strtotime((string)($ctx['starts_at'] ?? 'now')) ?: time();
        $map = [
            '{{name}}'         => e((string)($ctx['customer_name']  ?? '')),
            '{{service}}'      => e((string)($ctx['service_name']   ?? '')),
            '{{provider}}'     => e((string)($ctx['provider_name']  ?? '')),
            '{{when}}'         => e(date('l, j F Y, H:i', $ts)),
            '{{date}}'         => e(date('j M Y', $ts)),
            '{{time}}'         => e(date('H:i', $ts)),
            '{{ref}}'          => e((string)($ctx['ref']            ?? '')),
            '{{prep_url}}'     => e((string)($ctx['prep_url']       ?? '')),
            '{{whatsapp_url}}' => e((string)($ctx['whatsapp_url']   ?? '')),
            '{{payment_note}}' => (string)($ctx['payment_note']  ?? ''),  // pre-formatted HTML
            '{{zoom_url}}'     => e((string)($ctx['zoom_url']       ?? '')),
            '{{payment_link}}' => e((string)($ctx['payment_link']   ?? '')),
            '{{message_url}}'  => e((string)($ctx['message_url']    ?? '')),
        ];
        return strtr($tpl, $map);
    }

    /**
     * Build the "{{payment_note}}" HTML fragment from a booking service's
     * payment_mode. Kept here (not in template) so we can vary phrasing
     * per mode without every service overriding the template body.
     */
    public static function paymentNote(array $service): string {
        $mode = (string)($service['payment_mode'] ?? 'free');
        switch ($mode) {
            case 'free':
                return '<p>This session is free of charge — no payment required.</p>';
            case 'deposit':
                return '<p>The first hour is paid at booking; the balance is settled on the day of the session.</p>';
            case 'onsite':
                return '<p>Payment is handled in person at the session.</p>';
            case 'full':
            default:
                return '<p>You will receive your payment link 8 days before the session.</p>';
        }
    }

    // ── Prereq checking (used by the booking gate) ───────────────────────

    /**
     * Has this customer email ever completed the given service?
     * Used to enforce "HSR requires prior Discovery Call" style rules.
     */
    public static function customerHasCompleted(string $email, int $serviceId): bool {
        if ($email === '' || $serviceId <= 0) return false;
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT 1 FROM booking_appointments
              WHERE tenant_id = ? AND service_id = ? AND customer_email = ?
                AND status IN ('confirmed','completed')
                AND starts_at <= NOW()
              LIMIT 1",
            [$tid, $serviceId, $email]
        );
        return (bool) $row;
    }

    // ── Global settings helpers ──────────────────────────────────────────

    public static function globalWhatsappUrl(): string {
        return (string) (Database::setting('bookingplus.whatsapp_url') ?? '');
    }

    public static function globalNudgeHours(): int {
        $h = (int) (Database::setting('bookingplus.nudge_hours') ?? 8);
        return max(1, $h);
    }

    /** The reminder-lead comma list we seed on activate. */
    public static function defaultReminderLeads(): string {
        return '11520,1440,10'; // 8 days, 1 day, 10 minutes
    }

    /**
     * Persist Booking+'s own settings from Booking's settings POST.
     *
     * Called from `booking_settings_save`, which Booking fires from its own
     * screen. Two rules hold this to being a contribution rather than a second
     * owner:
     *
     *   1. It writes only `bookingplus.*`. `booking.reminder_leads` is
     *      Booking's, and used to be written here too — from a second screen,
     *      with a different default, so whichever the operator saved last won.
     *      Booking+ now influences only the DEFAULT, via
     *      `booking_default_reminder_leads`.
     *   2. Field names are prefixed, so a future Booking field cannot collide
     *      with one of these and be silently overwritten.
     *
     * Permission is checked here, not by Booking — Booking cannot know what
     * Booking+'s permission is called.
     */
    public static function saveSettings(array $post): void
    {
        if (!Auth::can('bookingplus.manage_settings') && !Auth::isSuperAdmin()) return;

        if (array_key_exists('bookingplus_whatsapp_url', $post)) {
            Database::setSetting('bookingplus.whatsapp_url', trim((string) $post['bookingplus_whatsapp_url']));
        }
        if (array_key_exists('bookingplus_nudge_hours', $post)) {
            Database::setSetting('bookingplus.nudge_hours', (string) max(1, min(72, (int) $post['bookingplus_nudge_hours'])));
        }
    }

    /** Booking+'s card, appended to Booking's settings form. */
    public static function settingsCard(string $html): string
    {
        if (!Auth::can('bookingplus.manage_settings') && !Auth::isSuperAdmin()) return $html;

        $wa    = e((string) (Database::setting('bookingplus.whatsapp_url') ?? ''));
        $nudge = (int) (Database::setting('bookingplus.nudge_hours') ?? 8);

        return $html . '
    <div class="app-card">
        <div class="card-header"><h2>Booking+</h2></div>
        <p class="text-sm text-muted">Human follow-up: the WhatsApp link offered after booking, and how long before an unanswered message is nudged.</p>
        <div class="field">
            <label class="field-label" for="bookingplus_whatsapp_url">WhatsApp link</label>
            <input type="url" id="bookingplus_whatsapp_url" name="bookingplus_whatsapp_url" maxlength="500" value="' . $wa . '" placeholder="https://wa.me/33612345678">
        </div>
        <div class="field">
            <label class="field-label" for="bookingplus_nudge_hours">Nudge after (hours)</label>
            <input type="number" id="bookingplus_nudge_hours" name="bookingplus_nudge_hours" min="1" max="72" value="' . $nudge . '">
        </div>
    </div>';
    }
}

}
