<?php
/**
 * Site Timeclock — bootstrap.
 * Slug "timeclock" → slugToClass → "Timeclock".
 */
class Timeclock extends Plugin {

    public function boot(): void {
        // Run migrations on version bump.
        $applied = (string) $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version(), '<')) {
            try { $this->runMigrations($applied); }
            catch (\Throwable $e) { if (function_exists('slate_log')) slate_log('timeclock migration: ' . $e->getMessage(), 'error'); }
            $this->setSetting('applied_version', $this->version());
        }

        // Load public API for cross-plugin use.
        $api = $this->dir('TimeclockAPI.php');
        if (file_exists($api)) require_once $api;

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('public_routes',   [$this, 'addPublicRoutes']);
    }

    public function addAdminNav(array $items): array {
        // A submenu, expressed the native Slate way: sibling rows sharing one
        // `group` label (same pattern the core uses for ClientDesk/Shop). Each
        // page is directly reachable and the active row highlights via
        // $currentNav matching the row slug.
        $nav = [
            ['timeclock',           'Time Entries', 'admin/index.php',     'clipboard-list', 'timeclock.view',   520],
            ['timeclock-employees', 'Employees',    'admin/employees.php', 'users',          'timeclock.manage', 521],
            ['timeclock-sites',     'Sites',        'admin/sites.php',     'map-pin',        'timeclock.manage', 522],
            ['timeclock-tasks',     'Tasks',        'admin/tasks.php',     'tag',            'timeclock.manage', 523],
            ['timeclock-reports',   'Reports',      'admin/reports.php',   'bar-chart-2',    'timeclock.view',   524],
            ['timeclock-docs',      'Documentation','admin/docs.php',      'list',           'timeclock.view',   525],
        ];
        foreach ($nav as [$slug, $label, $path, $icon, $perm, $order]) {
            $items[] = [
                'slug'  => $slug,
                'label' => $label,
                'href'  => $this->url($path),
                'icon'  => $icon,
                'perm'  => $perm,
                'order' => $order,
                'group' => 'Timeclock',
            ];
        }
        return $items;
    }

    public function addPublicRoutes(array $routes): array {
        // Employee-facing clock app at /timeclock
        $routes['timeclock'] = ['handler' => $this->dir('public/clock.php')];
        // Branded landing / front door at /clock-portal
        $routes['clock-portal'] = ['handler' => $this->dir('public/landing.php')];
        return $routes;
    }

    private function runMigrations(string $from): void {
        $dir = $this->dir('migrations');
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            sort($files);
            foreach ($files as $file) {
                $target = basename($file, '.sql');
                if (version_compare($target, $from, '>')) {
                    $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                        if ($stmt !== '') { try { Database::query($stmt); } catch (\Throwable $e) {} }
                    }
                }
            }
        }
    }
}
