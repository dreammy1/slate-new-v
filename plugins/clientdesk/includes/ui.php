<?php
/**
 * Client Desk — shared UI helpers.
 *
 * Small, dependency-free visual components built from the Slate design
 * tokens (inline SVG + CSS vars). Loaded by pages that need them via
 * require_once. All output is escaped; callers pass plain data.
 */

if (!function_exists('cd_progress_ring')) {

    /** Circular progress ring. $pct 0-100. */
    function cd_progress_ring(int $pct, int $size = 64, string $color = 'var(--accent)'): string {
        $pct = max(0, min(100, $pct));
        $r   = ($size / 2) - 6;
        $c   = 2 * M_PI * $r;
        $off = $c * (1 - $pct / 100);
        $cx  = $size / 2;
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" style="transform:rotate(-90deg)">'
            . '<circle cx="' . $cx . '" cy="' . $cx . '" r="' . $r . '" fill="none" stroke="var(--border)" stroke-width="6"/>'
            . '<circle cx="' . $cx . '" cy="' . $cx . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="6"'
            . ' stroke-linecap="round" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($off, 2) . '"'
            . ' style="transition:stroke-dashoffset .6s ease"/>'
            . '<text x="50%" y="50%" text-anchor="middle" dominant-baseline="central"'
            . ' style="transform:rotate(90deg);transform-origin:center;font:600 ' . round($size / 4) . 'px var(--font-display);fill:var(--text)">'
            . $pct . '%</text></svg>';
    }

    /** Linear progress bar with a colour ramp by completeness. */
    function cd_progress_bar(int $pct): string {
        $pct = max(0, min(100, $pct));
        $color = $pct >= 100 ? 'var(--success)' : ($pct >= 50 ? 'var(--accent)' : 'var(--warning)');
        return '<div class="cd-bar"><span style="width:' . $pct . '%;background:' . $color . '"></span></div>';
    }

    /** A single KPI tile. */
    function cd_stat_tile(string $label, string $value, ?string $sub = null, string $accent = 'var(--accent)'): string {
        $h = '<div class="cd-tile"><div class="cd-tile-bar" style="background:' . $accent . '"></div>'
           . '<div class="cd-tile-label">' . e($label) . '</div>'
           . '<div class="cd-tile-value">' . e($value) . '</div>';
        if ($sub !== null) $h .= '<div class="cd-tile-sub">' . e($sub) . '</div>';
        return $h . '</div>';
    }

    /**
     * Vertical bar chart. $data = [['label'=>'Jan','value'=>1200], ...].
     * Built from flexbox + CSS-height bars (not a stretched SVG) so it stays
     * crisp and correctly sized at any width. $height = bar-track height in px.
     */
    function cd_bar_chart(array $data, string $prefix = '', int $height = 180): string {
        if (!$data) return '<p class="text-muted text-sm">No data yet.</p>';
        $max  = max(1, max(array_map(fn($d) => (float)$d['value'], $data)));
        $bars = '';
        foreach ($data as $d) {
            $v   = (float)$d['value'];
            // Nonzero bars get a small floor (4%) so they stay visible; zero
            // values stay flat on the baseline.
            $pct = $v > 0 ? max(4, (int)round($v / $max * 100)) : 0;
            $val = $prefix . number_format($v);
            $bars .= '<div class="cd-bc-col" title="' . e($d['label'] . ': ' . $val) . '">'
                  .  '<div class="cd-bc-val">' . ($v > 0 ? e($val) : '') . '</div>'
                  .  '<div class="cd-bc-track"><div class="cd-bc-bar" style="height:' . $pct . '%"></div></div>'
                  .  '<div class="cd-bc-label">' . e($d['label']) . '</div>'
                  .  '</div>';
        }
        return '<div class="cd-bc" style="--cd-bc-h:' . (int)$height . 'px">' . $bars . '</div>';
    }

    /** Horizontal segmented breakdown bar (e.g. project phase mix). */
    function cd_segments(array $segments): string {
        // $segments = [['label'=>'Design','value'=>3,'color'=>'var(--accent)'], ...]
        $total = array_sum(array_map(fn($s) => (int)$s['value'], $segments)) ?: 1;
        $bar = '<div class="cd-seg">';
        foreach ($segments as $s) {
            if ((int)$s['value'] === 0) continue;
            $w = round(((int)$s['value'] / $total) * 100, 2);
            $bar .= '<span style="width:' . $w . '%;background:' . ($s['color'] ?? 'var(--accent)') . '" title="'
                  . e($s['label'] . ': ' . $s['value']) . '"></span>';
        }
        $bar .= '</div><div class="cd-seg-legend">';
        foreach ($segments as $s) {
            $bar .= '<span class="cd-seg-key"><i style="background:' . ($s['color'] ?? 'var(--accent)') . '"></i>'
                  . e($s['label']) . ' <b>' . (int)$s['value'] . '</b></span>';
        }
        return $bar . '</div>';
    }

    /** Phase stepper showing where a project is in the pipeline. */
    function cd_phase_stepper(string $current, array $phases): string {
        $keys = array_keys($phases);
        $idx  = array_search($current, $keys, true);
        if ($idx === false) $idx = 0;
        $h = '<div class="cd-stepper">';
        foreach ($keys as $i => $k) {
            $state = $i < $idx ? 'done' : ($i === $idx ? 'current' : 'todo');
            $h .= '<div class="cd-step cd-step-' . $state . '">'
               . '<span class="cd-step-dot">' . ($state === 'done' ? '✓' : ($i + 1)) . '</span>'
               . '<span class="cd-step-label">' . e($phases[$k]) . '</span></div>';
        }
        return $h . '</div>';
    }

    function cd_human_size(int $bytes): string {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Emit the plugin stylesheet inline, exactly once per request. The
     * Slate shells don't render plugin-enqueued styles, so we inline to
     * stay self-contained and avoid an extra HTTP request.
     */
    function cd_styles(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $css = @file_get_contents(__DIR__ . '/../assets/css/clientdesk.css');
        if ($css !== false) echo "<style>\n" . $css . "\n</style>\n";
    }
}
