
    // ─────────────────────────────────────────────────────────────
    // Customer self-service policy (cancel / reschedule)
    // ─────────────────────────────────────────────────────────────
    //
    // Admin-initiated cancel/reschedule (from the admin UI or API) always
    // calls cancelAppointment()/rescheduleAppointment() directly and bypasses
    // this policy by design — same convention as createAppointment()'s
    // source==='online' gate. Only the public self-service manage page
    // (plugins/booking/public/router.php, bookpub_manage()) calls these
    // first and refuses the action on ['ok'=>false].

    /**
     * Whether a customer may cancel this appointment online right now, per
     * the "Customer self-service" settings in Booking → Settings.
     */
    public static function canSelfCancel(array $appt): array {
        $enabled = (string)(Database::setting('booking.self_cancel_enabled') ?? '1');
        if ($enabled === '0') {
            return ['ok' => false, 'error' => 'Online cancellation isn\'t available for this booking. Please contact us directly.'];
        }
        if (in_array($appt['status'] ?? '', ['cancelled', 'completed', 'no_show'], true)) {
            return ['ok' => false, 'error' => 'This booking can no longer be cancelled online.'];
        }
        $startTs = strtotime((string)($appt['starts_at'] ?? ''));
        if ($startTs === false || $startTs <= time()) {
            return ['ok' => false, 'error' => 'This appointment has already started or passed — please contact us directly.'];
        }
        $minHours = max(0, (int)(Database::setting('booking.cancel_min_notice_hours') ?? 0));
        if ($minHours > 0 && ($startTs - time()) < $minHours * 3600) {
            return ['ok' => false, 'error' => 'Online cancellation requires at least ' . self::humanNotice($minHours)
                . ' notice. Please contact us directly to cancel this booking.'];
        }
        return ['ok' => true];
    }

    /**
     * Whether a customer may reschedule this appointment online right now,
     * per the "Customer self-service" settings in Booking → Settings.
     */
    public static function canSelfReschedule(array $appt): array {
        $enabled = (string)(Database::setting('booking.self_reschedule_enabled') ?? '1');
        if ($enabled === '0') {
            return ['ok' => false, 'error' => 'Online rescheduling isn\'t available for this booking. Please contact us directly.'];
        }
        if (in_array($appt['status'] ?? '', ['cancelled', 'completed', 'no_show'], true)) {
            return ['ok' => false, 'error' => 'This booking can no longer be rescheduled online.'];
        }
        $startTs = strtotime((string)($appt['starts_at'] ?? ''));
        if ($startTs === false || $startTs <= time()) {
            return ['ok' => false, 'error' => 'This appointment has already started or passed — please contact us directly.'];
        }
        $minHours = max(0, (int)(Database::setting('booking.reschedule_min_notice_hours') ?? 0));
        if ($minHours > 0 && ($startTs - time()) < $minHours * 3600) {
            return ['ok' => false, 'error' => 'Online rescheduling requires at least ' . self::humanNotice($minHours)
                . ' notice. Please contact us directly to reschedule this booking.'];
        }
        $maxResched = max(0, (int)(Database::setting('booking.max_reschedules') ?? 0));
        if ($maxResched > 0 && (int)($appt['reschedule_count'] ?? 0) >= $maxResched) {
            $times = $maxResched === 1 ? 'once' : "{$maxResched} times";
            return ['ok' => false, 'error' => "This booking has already been rescheduled the maximum allowed ({$times}). Please contact us directly."];
        }
        return ['ok' => true];
    }

    /** "24" -> "24 hours"; "48" -> "2 days"; used only for policy error text. */
    private static function humanNotice(int $hours): string {
        if ($hours % 24 === 0 && $hours >= 24) {
            $d = $hours / 24;
            return $d . ' day' . ($d === 1 ? '' : 's');
        }
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    /**
     * Busy intervals for $providerId on $date sourced from their connected
     * Google Calendar (meetings/events created outside Slate — vacations,
     * personal appointments, other systems). Empty when Google Calendar
     * isn't connected for this provider, or nothing overlaps this date.
     * Consumed by effectiveIntervals() so availability (and therefore
     * booking/reschedule validation) reflects the provider's real calendar,
     * not just Slate's own bookings.
     */
    private static function googleBusyIntervals(int $providerId, string $date): array {
        $tid = current_tenant_id();
        try {
            $rows = Database::rows(
                "SELECT starts_at, ends_at FROM booking_google_busy_blocks
                  WHERE provider_id = ? AND tenant_id = ?
                    AND starts_at < ? AND ends_at > ?",
                [$providerId, $tid, $date . ' 23:59:59', $date . ' 00:00:00']
            );
        } catch (\Throwable $e) {
            return []; // table not migrated yet on this install
        }
        $out = [];
        foreach ($rows as $r) {
            $s = strtotime((string)$r['starts_at']);
            $e = strtotime((string)$r['ends_at']);
            if ($s !== false && $e !== false && $e > $s) $out[] = [$s, $e];
        }
        return $out;
    }
