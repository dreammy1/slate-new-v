<?php
/**
 * Site Timeclock — public API.
 * All reads/writes for the plugin go through here so other plugins (and our own
 * pages) never touch the tables directly. Static methods, tenant-scoped.
 */
class TimeclockAPI {

    /* ---------------------------------------------------------------- lookups */

    public static function employees(): array {
        return Database::rows(
            "SELECT * FROM timeclock_employees WHERE tenant_id = ? ORDER BY name",
            [current_tenant_id()]
        );
    }

    public static function employee(int $id): ?array {
        return Database::row(
            "SELECT * FROM timeclock_employees WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    public static function sites(): array {
        return Database::rows(
            "SELECT * FROM timeclock_sites WHERE tenant_id = ? ORDER BY name",
            [current_tenant_id()]
        );
    }

    public static function site(int $id): ?array {
        return Database::row(
            "SELECT * FROM timeclock_sites WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    public static function tasks(): array {
        return Database::rows(
            "SELECT * FROM timeclock_tasks WHERE tenant_id = ? ORDER BY name",
            [current_tenant_id()]
        );
    }

    public static function task(int $id): ?array {
        return Database::row(
            "SELECT * FROM timeclock_tasks WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    /* ------------------------------------------------------------- active shift */

    public static function activeFor(int $employeeId): ?array {
        return Database::row(
            "SELECT * FROM timeclock_active WHERE employee_id = ? AND tenant_id = ?",
            [$employeeId, current_tenant_id()]
        );
    }

    /** Map of employee_id => active row, for "Clocked In" badges. */
    public static function activeMap(): array {
        $rows = Database::rows(
            "SELECT * FROM timeclock_active WHERE tenant_id = ?",
            [current_tenant_id()]
        );
        $map = [];
        foreach ($rows as $r) { $map[(int)$r['employee_id']] = $r; }
        return $map;
    }

    public static function clockIn(int $employeeId, int $siteId, string $clockIn, string $workDate): bool {
        $existing = self::activeFor($employeeId);
        if ($existing) {
            // Still clocked in from an earlier day — a genuine missed
            // clock-out, not just the kiosk being tapped twice today.
            // Surface it so an admin can fix the stale entry; the employee
            // themselves already sees "already clocked in" at the kiosk.
            if (($existing['work_date'] ?? '') !== $workDate && class_exists('Notifications')) {
                $emp = self::employee($employeeId);
                Notifications::add('Missed clock-out · ' . ($emp['name'] ?? 'An employee'), [
                    'body' => ($emp['name'] ?? 'An employee') . ' is still clocked in since '
                            . date('D j M', strtotime((string)$existing['work_date'])) . ' — needs a manual correction.',
                    'url'  => function_exists('plugin_url') ? plugin_url('timeclock', 'admin/employees.php') : '',
                    'icon' => 'alert-triangle',
                ]);
            }
            return false; // already clocked in
        }
        Database::insert('timeclock_active', [
            'tenant_id'   => current_tenant_id(),
            'employee_id' => $employeeId,
            'site_id'     => $siteId,
            'work_date'   => $workDate,
            'clock_in'    => self::sanitizeTime($clockIn),
        ]);
        return true;
    }

    /**
     * Close an open shift into a permanent entry.
     * $tasks is an array of up to 10 slots, each null or a task id.
     */
    public static function clockOut(int $employeeId, string $clockOut, array $tasks): ?int {
        $active = self::activeFor($employeeId);
        if (!$active) return null;

        $clockOut = self::sanitizeTime($clockOut);
        $hours    = self::hoursBetween($active['clock_in'], $clockOut);

        $id = self::createEntry([
            'employee_id' => (int)$active['employee_id'],
            'site_id'     => (int)$active['site_id'],
            'work_date'   => $active['work_date'],
            'clock_in'    => $active['clock_in'],
            'clock_out'   => $clockOut,
            'tasks'       => $tasks,
        ], $hours);

        Database::delete('timeclock_active', 'id = ? AND tenant_id = ?',
            [(int)$active['id'], current_tenant_id()]);
        return $id;
    }

    /* ----------------------------------------------------------------- entries */

    /**
     * Insert an entry. $data needs employee_id, site_id, work_date, clock_in,
     * clock_out, tasks (array of slot=>task_id|null). $hours optional (computed
     * from times if omitted).
     */
    public static function createEntry(array $data, ?float $hours = null): int {
        $clockIn  = self::sanitizeTime((string)($data['clock_in'] ?? '00:00'));
        $clockOut = self::sanitizeTime((string)($data['clock_out'] ?? '00:00'));
        if ($hours === null) $hours = self::hoursBetween($clockIn, $clockOut);

        return Database::insert('timeclock_entries', [
            'tenant_id'   => current_tenant_id(),
            'employee_id' => (int)$data['employee_id'],
            'site_id'     => (int)$data['site_id'],
            'work_date'   => (string)$data['work_date'],
            'clock_in'    => $clockIn,
            'clock_out'   => $clockOut,
            'total_hours' => round($hours, 2),
            'tasks_json'  => json_encode(self::normalizeTasks($data['tasks'] ?? [])),
        ]);
    }

    public static function updateEntry(int $id, array $data): int {
        $clockIn  = self::sanitizeTime((string)($data['clock_in'] ?? '00:00'));
        $clockOut = self::sanitizeTime((string)($data['clock_out'] ?? '00:00'));
        $hours    = self::hoursBetween($clockIn, $clockOut);

        return Database::update('timeclock_entries', [
            'employee_id' => (int)$data['employee_id'],
            'site_id'     => (int)$data['site_id'],
            'work_date'   => (string)$data['work_date'],
            'clock_in'    => $clockIn,
            'clock_out'   => $clockOut,
            'total_hours' => round($hours, 2),
            'tasks_json'  => json_encode(self::normalizeTasks($data['tasks'] ?? [])),
        ], 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
    }

    public static function deleteEntry(int $id): int {
        return Database::delete('timeclock_entries', 'id = ? AND tenant_id = ?',
            [$id, current_tenant_id()]);
    }

    public static function entry(int $id): ?array {
        return Database::row(
            "SELECT * FROM timeclock_entries WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    /** Filtered list. $filters: employee_id, date, from, to. */
    public static function entries(array $filters = []): array {
        $sql    = "SELECT * FROM timeclock_entries WHERE tenant_id = ?";
        $params = [current_tenant_id()];

        if (!empty($filters['employee_id'])) {
            $sql .= " AND employee_id = ?";
            $params[] = (int)$filters['employee_id'];
        }
        if (!empty($filters['site_id'])) {
            $sql .= " AND site_id = ?";
            $params[] = (int)$filters['site_id'];
        }
        if (!empty($filters['date'])) {
            $sql .= " AND work_date = ?";
            $params[] = $filters['date'];
        }
        if (!empty($filters['from'])) {
            $sql .= " AND work_date >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= " AND work_date <= ?";
            $params[] = $filters['to'];
        }
        $sql .= " ORDER BY work_date DESC, clock_in DESC";
        return Database::rows($sql, $params);
    }

    /* ------------------------------------------------------------------- tasks */

    /** Normalize a slot array into [slot => task_id] keeping only valid ints. */
    public static function normalizeTasks($tasks): array {
        $out = [];
        if (!is_array($tasks)) return $out;
        $i = 0;
        foreach ($tasks as $slot => $tid) {
            if ($i >= 10) break;
            if ($tid !== null && $tid !== '' && (int)$tid > 0) {
                $out[(string)$i] = (int)$tid;
            }
            $i++;
        }
        return $out;
    }

    public static function decodeTasks(?string $json): array {
        if (!$json) return [];
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /** [task_id => hours] for one entry (1 slot = 1 hour). */
    public static function taskHours(?string $json): array {
        $slots = self::decodeTasks($json);
        $hours = [];
        foreach ($slots as $tid) {
            $tid = (int)$tid;
            $hours[$tid] = ($hours[$tid] ?? 0) + 1;
        }
        return $hours;
    }

    /* ----------------------------------------------------------------- helpers */

    public static function sanitizeTime(string $t): string {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) {
            $h = max(0, min(23, (int)$m[1]));
            $mn = max(0, min(59, (int)$m[2]));
            return sprintf('%02d:%02d', $h, $mn);
        }
        return '00:00';
    }

    /** Decimal hours between two HH:MM strings; handles past-midnight. */
    public static function hoursBetween(string $in, string $out): float {
        [$ih, $im] = array_map('intval', explode(':', self::sanitizeTime($in)));
        [$oh, $om] = array_map('intval', explode(':', self::sanitizeTime($out)));
        $start = $ih * 60 + $im;
        $end   = $oh * 60 + $om;
        if ($end < $start) $end += 24 * 60; // crossed midnight
        return ($end - $start) / 60.0;
    }

    /** Period bounds. $type 'weekly'|'monthly', around a Y-m-d reference. */
    public static function periodRange(string $type, string $ref): array {
        $ts = strtotime($ref) ?: time();
        if ($type === 'monthly') {
            $from = date('Y-m-01', $ts);
            $to   = date('Y-m-t', $ts);
        } else {
            // ISO week: Monday..Sunday
            $dow  = (int) date('N', $ts);      // 1=Mon..7=Sun
            $from = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
            $to   = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days', $ts));
        }
        return ['from' => $from, 'to' => $to];
    }

    /* --------------------------------------------------------------------- CSV */

    /**
     * Build CSV text of completed entries. Columns: Date, Employee, Site,
     * Clock In, Clock Out, Total Hours, then one column per task type (hours).
     */
    public static function exportCsv(array $filters = []): string {
        $entries = self::entries($filters);
        $tasks   = self::tasks();
        $empMap  = [];
        foreach (self::employees() as $e) $empMap[(int)$e['id']] = $e['name'];
        $siteMap = [];
        foreach (self::sites() as $s) $siteMap[(int)$s['id']] = $s['name'];

        $header = ['Date', 'Employee', 'Site', 'Clock In', 'Clock Out', 'Total Hours'];
        foreach ($tasks as $t) $header[] = $t['name'];

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header);
        foreach ($entries as $en) {
            if (($en['clock_out'] ?? '') === '') continue; // completed only
            $th  = self::taskHours($en['tasks_json'] ?? null);
            $row = [
                $en['work_date'],
                $empMap[(int)$en['employee_id']]  ?? ('#' . $en['employee_id']),
                $siteMap[(int)$en['site_id']]     ?? ('#' . $en['site_id']),
                $en['clock_in'],
                $en['clock_out'],
                number_format((float)$en['total_hours'], 2, '.', ''),
            ];
            foreach ($tasks as $t) {
                $row[] = isset($th[(int)$t['id']]) ? (string)$th[(int)$t['id']] : '0';
            }
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Email address used by the "forgot to log" feature. */
    public static function ownerEmail(): string {
        $v = Database::setting('timeclock.owner_email');
        if (is_string($v) && $v !== '') return $v;
        $biz = Database::setting('business_email');
        return is_string($biz) ? $biz : '';
    }
}
