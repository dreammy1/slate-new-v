<?php
/**
 * Site Timeclock — shared view helpers.
 * Enqueued styles don't render in this build (guide §19) so we inline once.
 */

if (!function_exists('tc_styles')) {
    function tc_styles(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $css = @file_get_contents(__DIR__ . '/../assets/css/timeclock.css');
        if ($css !== false) echo "<style>\n" . $css . "\n</style>\n";
    }
}

if (!function_exists('tc_task_bar')) {
    /**
     * Render a coloured proportional bar + chips for an entry's task slots.
     * $taskHours: [task_id => hours]; $taskMap: [task_id => ['name','color']].
     */
    function tc_task_bar(array $taskHours, array $taskMap): string {
        if (!$taskHours) return '<span class="tc-muted">—</span>';
        $total = array_sum($taskHours);
        if ($total <= 0) return '<span class="tc-muted">—</span>';

        $bar = '<div class="tc-taskbar">';
        foreach ($taskHours as $tid => $h) {
            $color = $taskMap[$tid]['color'] ?? '#6B7280';
            $pct   = ($h / $total) * 100;
            $bar  .= '<span class="tc-taskbar-seg" style="width:' . round($pct, 2)
                   . '%;background:' . e($color) . '" title="'
                   . e(($taskMap[$tid]['name'] ?? ('#' . $tid)) . ': ' . $h . 'h') . '"></span>';
        }
        $bar .= '</div>';

        $chips = '<div>';
        foreach ($taskHours as $tid => $h) {
            $color = $taskMap[$tid]['color'] ?? '#6B7280';
            $name  = $taskMap[$tid]['name'] ?? ('#' . $tid);
            $chips .= '<span class="tc-task-chip" style="background:' . e($color) . '">'
                    . e($name) . ' · ' . e((string)$h) . 'h</span>';
        }
        $chips .= '</div>';
        return $bar . $chips;
    }
}

if (!function_exists('tc_task_map')) {
    function tc_task_map(): array {
        $map = [];
        foreach (TimeclockAPI::tasks() as $t) {
            $map[(int)$t['id']] = ['name' => $t['name'], 'color' => $t['color']];
        }
        return $map;
    }
}

if (!function_exists('tc_subnav')) {
    /** In-page pill sub-nav mirroring the sidebar submenu. $active = page key. */
    function tc_subnav(string $active): void {
        $base  = SLATE_URL . '/plugins/timeclock/admin/';
        $canM  = Auth::can('timeclock.manage');
        $items = [
            ['entries',   'Time Entries', 'index.php',     true],
            ['employees', 'Employees',    'employees.php', $canM],
            ['sites',     'Sites',        'sites.php',     $canM],
            ['tasks',     'Tasks',        'tasks.php',     $canM],
            ['reports',   'Reports',      'reports.php',   true],
            ['docs',      'Docs',         'docs.php',      true],
        ];
        echo '<nav class="tc-subnav" aria-label="Timeclock sections">';
        foreach ($items as [$key, $label, $file, $show]) {
            if (!$show) continue;
            $cls = ($key === $active) ? ' class="is-active"' : '';
            $aria = ($key === $active) ? ' aria-current="page"' : '';
            echo '<a href="' . e($base . $file) . '"' . $cls . $aria . '>' . e($label) . '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('tc_icon')) {
    /** Tiny inline-SVG set for stat tiles (self-contained, no registry dependency). */
    function tc_icon(string $name): string {
        $p = [
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'layers' => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="M3 13l9 5 9-5"/>',
            'pin'    => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'tag'    => '<path d="M3 11l8-8 9 9-8 8-9-9z"/><circle cx="8" cy="8" r="1.5"/>',
        ];
        $body = $p[$name] ?? '<circle cx="12" cy="12" r="9"/>';
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $body . '</svg>';
    }
}
