<?php
/**
 * SiteHub — central control plane for PortKit-equipped WordPress sites.
 *
 * Talks to each site's PortKit Remote API over HTTPS (no direct DB access).
 * Tokens are stored encrypted with slate_encrypt_secret().
 */
class Sitehub extends Plugin {

    public function boot(): void {
        $apiFile = $this->dir('SitehubAPI.php');
        if (file_exists($apiFile)) {
            require_once $apiFile;
        }

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);
        Hook::addAction('daily_cron', [$this, 'dailyHealthCheck']);
    }

    public function addAdminNav(array $items): array {
        $items[] = [
            'slug'  => 'sitehub',
            'label' => __('sitehub', 'SiteHub'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'server',
            'perm'  => 'sitehub.view',
            'order' => 620,
            'group' => 'system',
        ];
        return $items;
    }

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('sitehub.view')) return $widgets;
        $total  = (int)Database::value(
            "SELECT COUNT(*) FROM sitehub_sites WHERE tenant_id = ?",
            [current_tenant_id()]
        );
        $online = (int)Database::value(
            "SELECT COUNT(*) FROM sitehub_sites WHERE tenant_id = ? AND status = 'online'",
            [current_tenant_id()]
        );
        ob_start();
        ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('sitehub', 'SiteHub') ?></h2>
                <span class="badge badge-active"><?= $online ?>/<?= $total ?></span>
            </div>
            <p class="text-sub">
                <?= sprintf(__('sitehub_widget_summary',
                    '%d of %d connected site(s) online.'), $online, $total) ?>
            </p>
            <a href="<?= e($this->url('admin/index.php')) ?>" class="btn btn-sm mt-2">
                <?= __('open_sitehub', 'Open SiteHub') ?> →
            </a>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /** Nightly: ping + scan every registered site so the dashboard stays fresh. */
    public function dailyHealthCheck(): void {
        $sites = Database::rows("SELECT id FROM sitehub_sites");
        foreach ($sites as $s) {
            SitehubAPI::ping((int)$s['id']);
            SitehubAPI::scan((int)$s['id'], true);
        }
    }
}
