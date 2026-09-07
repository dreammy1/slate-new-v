<?php
/**
 * Booking+ plugin — bootstrap.
 *
 * Registers the admin nav, wires the booking hooks (gate, post-create
 * auto-response, cron nudge), and seeds the multi-tier reminder cadence
 * on first activate.
 *
 * Every feature attaches through the public Hook system + BookingAPI
 * public methods — no booking internals are touched. If the booking
 * plugin isn't active, this plugin degrades silently (its own admin
 * pages still work; the hooks are just no-ops).
 */

require_once __DIR__ . '/BookingPlusAPI.php';

class BookingPlus extends Plugin {

    public function boot(): void {
        // If Booking plugin natively provides capabilities, delegate to it and avoid duplicate hooks
        if (class_exists('PluginLoader') && PluginLoader::hasCapability('booking', 'client_messaging')) {
            // Native Booking now owns these capabilities. BookingPlus gracefully delegates to avoid duplicate hooks.
            return;
        }

        // Schema self-heal — idempotent. Version-stamp skips the work on
        // the hot path once we're at the current version.
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            BookingPlusAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        // Admin surface.
        Hook::addFilter('admin_nav_items',         [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addAdminDashboardWidget']);

        // Booking integration. Every hook here is a no-op if the booking
        // plugin isn't loaded (the filter/action just doesn't fire).
        Hook::addFilter('booking_can_book',   [$this, 'gateBooking'], 20, 2);
        Hook::addAction('booking_created',    [$this, 'onBookingCreated'], 20, 3);
        Hook::addAction('frequent_cron',      [$this, 'runCron']);

        // CON-1: contribute into Booking's settings screen instead of owning a
        // second one. Booking fires both of these from its own POST and form.
        Hook::addFilter('booking_settings_cards',         [BookingPlusAPI::class, 'settingsCard']);
        Hook::addAction('booking_settings_save',          [BookingPlusAPI::class, 'saveSettings'], 10, 1);
        // Influence the DEFAULT cadence without writing Booking's setting.
        Hook::addFilter('booking_default_reminder_leads', [BookingPlusAPI::class, 'defaultReminderLeads'], 10, 1);

        // Extension point for the per-service reminder body overlay. This
        // only fires if the booking core has the matching filter (Path A
        // of the spec — a 3-line patch to Booking core). If it doesn't,
        // reminders continue to use booking's generic template unchanged.
        Hook::addFilter('booking_reminder_body', [$this, 'overrideReminderBody'], 10, 3);

        // Slot restrictions — hide slots that are reserved for a different service.
        Hook::addFilter('booking_slot_allowed', [$this, 'applySlotRestriction'], 10, 6);
    }

    // ── Admin nav ────────────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        // Silent if the user has no Booking+ permission and isn't super-admin —
        // matches booking's own gate style.
        if (!Auth::can('bookingplus.manage_settings')
            && !Auth::can('bookingplus.reply_messages')
            && !Auth::isSuperAdmin()) {
            return $items;
        }
        // Flat items grouped under 'booking' so the section matches core Booking.
        $items[] = ['slug' => 'bookingplus-messages',  'label' => 'Booking+ Messages',
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'message-square', 'perm' => 'bookingplus.reply_messages',
                    'order' => 690, 'group' => 'booking'];
        $items[] = ['slug' => 'bookingplus-services', 'label' => 'Booking+ Services',
                    'href' => $this->url('admin/services.php'),
                    'icon' => 'sliders', 'perm' => 'bookingplus.manage_settings',
                    'order' => 691, 'group' => 'booking'];
        $items[] = ['slug' => 'bookingplus-restrictions', 'label' => 'Booking+ Reserved slots',
                    'href' => $this->url('admin/restrictions.php'),
                    'icon' => 'calendar', 'perm' => 'bookingplus.manage_settings',
                    'order' => 691, 'group' => 'booking'];
        return $items;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        // Only surface when Booking+ has something to say (any pending
        // human-message threads waiting for a reply).
        try {
            $tid = current_tenant_id();
            $pending = (int) Database::value(
                "SELECT COUNT(*) FROM bookingplus_appointment_meta
                  WHERE tenant_id = ? AND client_message IS NOT NULL
                    AND therapist_replied_at IS NULL",
                [$tid]
            );
        } catch (\Throwable $e) { $pending = 0; }
        if ($pending <= 0) return $widgets;

        $widgets[] = [
            'title'  => 'Booking+ · pending replies',
            'render' => function () use ($pending) {
                $href = SLATE_URL . '/admin/plugins/booking-plus/index.php';
                return '<div class="dwidget">'
                     . '<div class="dwidget-kpi"><a href="' . e($href) . '">' . (int)$pending . '</a></div>'
                     . '<div class="dwidget-label">client message' . ($pending === 1 ? '' : 's') . ' waiting for your reply</div>'
                     . '</div>';
            },
        ];
        return $widgets;
    }

    // ── Booking gate ─────────────────────────────────────────────────────

    /**
     * Filter callback for `booking_can_book`. Enforces per-service:
     *   • min_advance_days  — HSR needs a 3-week preparation period
     *   • prereq_service_id — HSR requires a prior completed Discovery Call
     *
     * Runs only on `source='online'` bookings (the booking core already
     * scopes this filter that way). Admin walk-ins bypass by design.
     */
    public function gateBooking($gate, array $ctx = []) {
        // Respect an earlier listener that already blocked.
        if (is_array($gate) && array_key_exists('ok', $gate) && $gate['ok'] === false) {
            return $gate;
        }

        $service = $ctx['service'] ?? null;
        if (!is_array($service) || empty($service['id'])) return $gate;
        $cfg = BookingPlusAPI::getServiceConfig((int)$service['id']);

        // Min-advance-days.
        $minDays = (int)($cfg['min_advance_days'] ?? 0);
        if ($minDays > 0) {
            $start = strtotime((string)($ctx['starts_at'] ?? ''));
            if ($start && $start < time() + $minDays * 86400) {
                $nextOk = date('l, j F Y', time() + $minDays * 86400);
                return [
                    'ok'    => false,
                    'error' => 'This session requires a preparation period of at least '
                             . $minDays . ' days. The earliest we can book is ' . $nextOk . '.',
                ];
            }
        }

        // Prereq service (has this customer completed the required prior service?).
        $prereqId = (int)($cfg['prereq_service_id'] ?? 0);
        if ($prereqId > 0) {
            // Prefer looking up by booking customer id when present, but we
            // may only have the email on an online booking. Try both paths.
            $email = self::emailFromContext($ctx);
            $ok = $email !== '' && BookingPlusAPI::customerHasCompleted($email, $prereqId);
            if (!$ok) {
                $msg = trim((string)($cfg['prereq_message'] ?? ''));
                if ($msg === '') {
                    $msg = 'This session requires a prior consultation. Please book a Discovery Call first — we can talk it through together.';
                }
                // If a redirect target is configured, send them to a friendly
                // banner page that explains the switch and forwards them to
                // the correct service's booking widget.
                $redirectId = (int)($cfg['hsr_redirect_service_id'] ?? 0);
                if ($redirectId > 0 && defined('SLATE_URL')) {
                    $return = [
                        'ok'          => false,
                        'error'       => $msg,
                        'redirect_to' => SLATE_URL . '/plugins/booking-plus/public/prereq.php'
                                       . '?from=' . (int)($service['id'] ?? 0)
                                       . '&to='   . $redirectId,
                    ];
                    return $return;
                }
                return ['ok' => false, 'error' => $msg];
            }
        }

        return $gate;
    }

    /**
     * The `booking_can_book` filter fires before the customer record has
     * been resolved to an id — we work with whatever identifying data the
     * context carries. Falls back to a Database lookup by customer_id.
     */
    private static function emailFromContext(array $ctx): string {
        if (!empty($ctx['customer_email'])) return (string)$ctx['customer_email'];
        $cid = (int)($ctx['customer_id'] ?? 0);
        if ($cid > 0) {
            $row = Database::row("SELECT email FROM customers WHERE id = ?", [$cid]);
            if ($row && !empty($row['email'])) return (string)$row['email'];
        }
        // As a last resort, the booking payload sometimes carries email in
        // $ctx['payload'] — kept generic in case that shape ever appears.
        if (!empty($ctx['payload']['customer_email'])) return (string)$ctx['payload']['customer_email'];
        return '';
    }

    // ── Post-booking auto-response ───────────────────────────────────────

    /**
     * Fires on `booking_created(id, serviceId, providerId)`. Sends the
     * per-service immediate follow-up email with the prep-page + WhatsApp
     * links + payment note. Silent no-op if the service has no
     * auto_response_body configured (booking's own confirmation still ran).
     */
    public function onBookingCreated(int $apptId, int $serviceId, int $providerId): void {
        try {
            $cfg = BookingPlusAPI::getServiceConfig($serviceId);
            $body = trim((string)($cfg['auto_response_body'] ?? ''));
            if ($body === '') return;

            // Booking's public getter for the appointment record.
            if (!class_exists('BookingAPI')) return;
            $appt = Database::row(
                "SELECT a.*, s.name AS service_name, s.payment_mode, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.id = ? LIMIT 1",
                [$apptId]
            );
            if (!$appt || empty($appt['customer_email'])) return;

            $whatsapp = trim((string)($cfg['whatsapp_url'] ?? '')) ?: BookingPlusAPI::globalWhatsappUrl();

            // Personal-message link the client uses to reach the therapist —
            // gated by the appointment's manage_token, no login required.
            $messageUrl = '';
            if (!empty($appt['manage_token']) && defined('SLATE_URL')) {
                $messageUrl = SLATE_URL . '/plugins/booking-plus/public/message.php'
                            . '?t=' . rawurlencode((string)$appt['manage_token']);
            }

            $ctx = [
                'customer_name' => $appt['customer_name'] ?? '',
                'service_name'  => $appt['service_name']  ?? '',
                'provider_name' => $appt['provider_name'] ?? '',
                'starts_at'     => $appt['starts_at']     ?? 'now',
                'ref'           => $appt['ref']           ?? '',
                'prep_url'      => (string)($cfg['prep_page_url'] ?? ''),
                'whatsapp_url'  => $whatsapp,
                'payment_note'  => BookingPlusAPI::paymentNote($appt),
                'message_url'   => $messageUrl,
            ];

            $subject = trim((string)($cfg['auto_response_subject'] ?? '')) ?: 'A little more about your booking';
            $subject = BookingPlusAPI::renderTemplate($subject, $ctx);
            $html    = BookingPlusAPI::renderTemplate($body, $ctx);

            // Append a default message-page block if the template didn't include
            // its own {{message_url}} placeholder — so the link is always there
            // even when the operator forgets to add it.
            if ($messageUrl !== '' && strpos($body, '{{message_url}}') === false) {
                $html .= '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">'
                       . '<p style="margin:0 0 6px;font-weight:600;">Anything you\'d like me to know?</p>'
                       . '<p style="margin:0 0 10px;color:#555;">Leave me a short personal note about what brings you — questions, concerns, context. I read every one.</p>'
                       . '<p style="margin:0;"><a href="' . e($messageUrl)
                       . '" style="display:inline-block;padding:10px 18px;background:#2563EB;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Send me a message</a></p>';
            }

            Mailer::send(
                (string)$appt['customer_email'],
                $subject,
                $html,
                (string)$appt['customer_name']
            );
        } catch (\Throwable $e) {
            slate_log('BookingPlus auto-response failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Reminder body overlay (Path A — needs booking-core filter) ───────

    /**
     * Filter callback for `booking_reminder_body(body, appt, lead_min)`.
     * Returns the service's per-lead-time body override when configured,
     * otherwise passes the default through unchanged.
     *
     * The three lead times we recognize (matches the seeded cadence):
     *   11520 → 8 days   → reminder_8day_body   (includes payment link)
     *    1440 → 1 day    → reminder_1day_body   (includes Zoom link)
     *      10 → 10 min   → reminder_10min_body  (final ping)
     */
    public function overrideReminderBody(string $body, array $appt, int $leadMinutes): string {
        try {
            $serviceId = (int)($appt['service_id'] ?? 0);
            if ($serviceId <= 0) return $body;
            $cfg = BookingPlusAPI::getServiceConfig($serviceId);

            $tpl = null;
            if ($leadMinutes === 11520) $tpl = $cfg['reminder_8day_body']  ?? null;
            elseif ($leadMinutes === 1440) $tpl = $cfg['reminder_1day_body']  ?? null;
            elseif ($leadMinutes === 10)   $tpl = $cfg['reminder_10min_body'] ?? null;
            if (!$tpl || trim((string)$tpl) === '') return $body;

            $whatsapp = trim((string)($cfg['whatsapp_url'] ?? '')) ?: BookingPlusAPI::globalWhatsappUrl();

            // Manage token drives the payment page + reschedule/cancel UI.
            $paymentLink = '';
            if (!empty($appt['manage_token']) && defined('SLATE_URL')) {
                $paymentLink = SLATE_URL . '/book/manage?token=' . rawurlencode((string)$appt['manage_token']);
            }

            // Zoom URL preference: per-appointment override > service default.
            $meta = BookingPlusAPI::getAppointmentMeta((int)($appt['id'] ?? 0));
            $zoom = trim((string)($meta['zoom_join_url'] ?? '')) ?: trim((string)($cfg['zoom_join_url'] ?? ''));
            if (($cfg['zoom_mode'] ?? '') === 'fallback_message' && $zoom === '') {
                $zoom = ''; // template can print a "link arriving by email" line itself
            }

            $ctx = [
                'customer_name' => $appt['customer_name'] ?? '',
                'service_name'  => $appt['service_name']  ?? '',
                'provider_name' => $appt['provider_name'] ?? '',
                'starts_at'     => $appt['starts_at']     ?? 'now',
                'ref'           => $appt['ref']           ?? '',
                'prep_url'      => (string)($cfg['prep_page_url'] ?? ''),
                'whatsapp_url'  => $whatsapp,
                'zoom_url'      => $zoom,
                'payment_link'  => $paymentLink,
                'payment_note'  => BookingPlusAPI::paymentNote($appt),
            ];
            return BookingPlusAPI::renderTemplate((string)$tpl, $ctx);
        } catch (\Throwable $e) {
            slate_log('BookingPlus reminder overlay failed: ' . $e->getMessage(), 'warning');
            return $body;
        }
    }

    // ── Slot restrictions filter ─────────────────────────────────────────

    /**
     * Callback for `booking_slot_allowed(bool $ok, int $serviceId, int $providerId,
     *   string $date, int $startTs, int $endTs)`.
     *
     * A row in bookingplus_slot_restrictions "reserves" a weekly time window
     * for a specific service (optionally scoped to one provider). When ANY
     * restriction row overlaps the candidate slot, the slot is HIDDEN unless
     * one of the overlapping rows is for the service currently being booked.
     */
    public function applySlotRestriction($allowed, int $serviceId, int $providerId, string $date, int $startTs, int $endTs) {
        if (!$allowed) return false;
        try {
            $dow = (int) date('w', $startTs);
            $rows = Database::rows(
                "SELECT service_id FROM bookingplus_slot_restrictions
                  WHERE tenant_id = ? AND day_of_week = ?
                    AND (provider_id IS NULL OR provider_id = ?)
                    AND start_time < ? AND end_time > ?",
                [current_tenant_id(), $dow, $providerId,
                 date('H:i:s', $endTs), date('H:i:s', $startTs)]
            );
            if (!$rows) return true;
            foreach ($rows as $r) {
                if ((int)$r['service_id'] === $serviceId) return true;
            }
            return false;
        } catch (\Throwable $e) {
            slate_log('BookingPlus slot restriction check failed: ' . $e->getMessage(), 'warning');
            return $allowed;
        }
    }

    // ── Cron: 8-hour internal nudge ──────────────────────────────────────

    /**
     * Runs alongside booking's own reminder cron. Emails the therapist
     * once when a client has left a human message and no reply has been
     * recorded within the configured nudge window (default 8 hours).
     */
    public function runCron(): void {
        try {
            $tid   = current_tenant_id();
            $hours = BookingPlusAPI::globalNudgeHours();

            $rows = Database::rows(
                "SELECT m.*, a.customer_name, a.customer_email, s.name AS service_name
                   FROM bookingplus_appointment_meta m
                   JOIN booking_appointments a ON a.id = m.appointment_id
                   JOIN booking_services    s ON s.id = a.service_id
                  WHERE m.tenant_id = ?
                    AND m.client_message IS NOT NULL
                    AND m.therapist_replied_at IS NULL
                    AND m.nudge_sent_at IS NULL
                    AND m.therapist_notified_at IS NOT NULL
                    AND m.therapist_notified_at <= NOW() - INTERVAL ? HOUR",
                [$tid, $hours]
            );
            if (!$rows) return;

            $to = trim((string) (Database::setting('booking.notify_admin_email') ?: Database::setting('site_admin_email') ?: ''));
            if ($to === '') return;
            $siteName = Database::setting('site_name') ?: 'Slate';

            foreach ($rows as $m) {
                $subj = $siteName . ' · client message still waiting for your reply';
                $body = '<p>A client sent you a message ' . $hours . 'h+ ago and no reply is recorded yet.</p>'
                      . '<p><strong>' . e((string)$m['customer_name']) . '</strong> · '
                      . e((string)$m['service_name']) . '</p>'
                      . '<blockquote style="border-left:3px solid #ccc;padding:8px 12px;color:#444;">'
                      . nl2br(e((string)$m['client_message']))
                      . '</blockquote>'
                      . '<p><a href="' . e(SLATE_URL . '/admin/plugins/booking-plus/index.php') . '">Open Booking+ messages</a></p>';
                Mailer::send($to, $subj, $body);
                Database::update('bookingplus_appointment_meta',
                    ['nudge_sent_at' => slate_db_now()],   // CORE-1: DB clock
                    'id = ?', [(int)$m['id']]
                );
            }
        } catch (\Throwable $e) {
            slate_log('BookingPlus nudge cron failed: ' . $e->getMessage(), 'error');
        }
    }
}
