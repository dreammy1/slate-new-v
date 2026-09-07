<?php
/**
 * Client Desk — bootstrap (v2).
 *
 * Registers the admin sidebar group, an analytics overview, dashboard
 * widgets, the public per-client login route, a customer dashboard
 * widget, schema migrations, plugin assets, daily automations, and
 * Stripe webhook handling for invoice payments.
 */

class Clientdesk extends Plugin {

    public function boot(): void {
        // Versioned migrations (mirrors Booking/Shop). install.sql is
        // re-run idempotently on upgrade; this adds the 2.x tables/columns
        // on installs that predate them.
        $applied = (string) $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version(), '<')) {
            try { $this->runMigrations($applied); } catch (\Throwable $e) {
                slate_log('ClientDesk migration error: ' . $e->getMessage(), 'error');
            }
            $this->setSetting('applied_version', $this->version());
        }

        Hook::addFilter('admin_nav_items',            [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',    [$this, 'addDashboardWidget']);
        Hook::addFilter('customer_dashboard_widgets',  [$this, 'addCustomerWidget']);
        Hook::addFilter('customer_nav_items',          [$this, 'addCustomerNav']);
        Hook::addFilter('public_routes',              [$this, 'addPublicRoutes']);
        Hook::addAction('daily_cron',                 [$this, 'runDailyAutomations']);
        Hook::addAction('stripe_webhook_event',       [$this, 'onStripeEvent']);
        Hook::addAction('customer_registered',        [$this, 'onCustomerRegistered']);

        $api = $this->dir('ClientDeskAPI.php');
        if (file_exists($api)) require_once $api;
    }

    private function isOwnAdminPage(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/plugins/clientdesk/') !== false;
    }

    /* ---------------- nav + widgets ---------------- */

    public function addAdminNav(array $items): array {
        $g = 'clientdesk';
        $items[] = ['slug'=>'clientdesk-overview','label'=>__('cd_overview','Overview'),
            'href'=>$this->url('admin/overview.php'),'icon'=>'bar-chart-2',
            'perm'=>'clientdesk.view','order'=>505,'group'=>$g];
        $items[] = ['slug'=>'clientdesk-clients','label'=>__('cd_clients','Clients'),
            'href'=>$this->url('admin/index.php'),'icon'=>'users',
            'perm'=>'clientdesk.view','order'=>510,'group'=>$g];
        $items[] = ['slug'=>'clientdesk-projects','label'=>__('cd_projects','Projects'),
            'href'=>$this->url('admin/projects.php'),'icon'=>'folder',
            'perm'=>'clientdesk.manage_projects','order'=>520,'group'=>$g];
        $items[] = ['slug'=>'clientdesk-quotes','label'=>__('cd_quotes','Quotes'),
            'href'=>$this->url('admin/quotes.php'),'icon'=>'clipboard-list',
            'perm'=>'clientdesk.manage_quotes','order'=>525,'group'=>$g];
        $items[] = ['slug'=>'clientdesk-invoices','label'=>__('cd_invoices','Invoices'),
            'href'=>$this->url('admin/invoices.php'),'icon'=>'credit-card',
            'perm'=>'clientdesk.manage_invoices','order'=>530,'group'=>$g];
        $items[] = ['slug'=>'clientdesk-tickets','label'=>__('cd_support','Support'),
            'href'=>$this->url('admin/tickets.php'),'icon'=>'mail',
            'perm'=>'clientdesk.handle_support','order'=>540,'group'=>$g];
        return $items;
    }

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('clientdesk.view')) return $widgets;
        $t = current_tenant_id();
        $clients = (int) Database::value("SELECT COUNT(*) FROM clientdesk_clients  WHERE tenant_id=? AND status!='archived'", [$t]);
        $active  = (int) Database::value("SELECT COUNT(*) FROM clientdesk_projects WHERE tenant_id=? AND phase!='complete'", [$t]);
        $openTix = (int) Database::value("SELECT COUNT(*) FROM clientdesk_tickets  WHERE tenant_id=? AND status IN ('open','in_progress')", [$t]);
        $unpaid  = (int) Database::value("SELECT COALESCE(SUM(total_cents),0) FROM clientdesk_invoices WHERE tenant_id=? AND status IN ('sent','overdue')", [$t]);

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('cd_title','Client Desk') ?></h2>
                <a href="<?= e($this->url('admin/overview.php')) ?>" class="btn btn-sm"><?= __('cd_open','Open') ?> →</a>
            </div>
            <div class="stat-grid">
                <div class="stat"><div class="stat-label"><?= __('cd_clients','Clients') ?></div><div class="stat-value"><?= $clients ?></div></div>
                <div class="stat"><div class="stat-label"><?= __('cd_active_projects','Active projects') ?></div><div class="stat-value"><?= $active ?></div></div>
                <div class="stat"><div class="stat-label"><?= __('cd_open_tickets','Open tickets') ?></div><div class="stat-value"><?= $openTix ?></div></div>
                <div class="stat"><div class="stat-label"><?= __('cd_outstanding','Outstanding') ?></div><div class="stat-value">$<?= number_format($unpaid/100) ?></div></div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /**
     * "My projects" in the shared customer portal nav — only for a customer who
     * actually maps to a client with projects, matching the widget's own gate.
     * A customer who just books an appointment never sees it.
     */
    public function addCustomerNav(array $items): array {
        if (!class_exists('Auth')) return $items;
        $cid = Auth::customerId();
        if ($cid === null) return $items;

        try {
            $cust   = Auth::customer();
            $client = ClientDeskAPI::clientForCustomer($cid, $cust['email'] ?? null);
            if (!$client || !ClientDeskAPI::projectsForClient((int) $client['id'])) return $items;
        } catch (\Throwable $e) {
            return $items;
        }

        $items[] = [
            'slug'  => 'clientdesk',
            'label' => __('clientdesk_my_projects', 'My projects'),
            'href'  => SLATE_URL . '/plugins/clientdesk/customer/dashboard.php',
            'icon'  => 'folder',
            'order' => 400,
            'group' => 'clientdesk',
        ];
        return $items;
    }

    public function addCustomerWidget(array $widgets): array {
        $cid = Auth::customerId();
        if ($cid === null) return $widgets;
        $cust = Auth::customer();
        $client = ClientDeskAPI::clientForCustomer($cid, $cust['email'] ?? null);
        if (!$client) return $widgets;
        $projects = ClientDeskAPI::projectsForClient((int)$client['id']);
        if (!$projects) return $widgets;

        $portalUrl = SLATE_URL . '/plugins/clientdesk/customer/dashboard.php';
        ob_start(); ?>
        <style>
        .cdw-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:14px; }
        .cdw-item { min-width:0; }
        .cdw-row { display:flex; justify-content:space-between; align-items:baseline; gap:12px; }
        .cdw-title { font-weight:600; color:var(--text); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .cdw-phase { font-size:12px; color:var(--muted); white-space:nowrap; flex:none; }
        .cdw-bar { margin-top:7px; height:7px; border-radius:999px; background:var(--surface-2); overflow:hidden; }
        .cdw-bar > span { display:block; height:100%; border-radius:999px; background:var(--accent); transition:width .5s ease; }
        .cdw-pct { font-size:11px; color:var(--subtle); margin-top:4px; }
        </style>
        <div class="card">
            <div class="card-header">
                <h2><?= __('cd_your_projects','Your projects') ?></h2>
                <span class="badge badge-accent"><?= count($projects) ?></span>
            </div>
            <ul class="cdw-list">
                <?php foreach ($projects as $p):
                    $pct = max(0, min(100, (int)$p['progress'])); ?>
                    <li class="cdw-item">
                        <div class="cdw-row">
                            <span class="cdw-title"><?= e($p['title']) ?></span>
                            <span class="cdw-phase"><?= e(ClientDeskAPI::phaseLabel($p['phase'])) ?></span>
                        </div>
                        <div class="cdw-bar"><span style="width:<?= $pct ?>%"></span></div>
                        <div class="cdw-pct"><?= $pct ?>% <?= __('cd_complete','complete') ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= e($portalUrl) ?>" class="btn btn-sm btn-block mt-3"><?= __('cd_view_dashboard','Open portal') ?> →</a>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    public function addPublicRoutes(array $routes): array {
        $routes['portal'] = ['handler' => $this->dir('public/portal.php')];
        return $routes;
    }

    /* ---------------- automations ---------------- */

    public function runDailyAutomations(): void {
        $t = current_tenant_id();
        // Mark sent invoices past due as overdue + notify.
        $overdue = Database::rows(
            "SELECT id, number FROM clientdesk_invoices
              WHERE tenant_id=? AND status='sent' AND due_date IS NOT NULL AND due_date < CURDATE()", [$t]);
        foreach ($overdue as $inv) {
            Database::update('clientdesk_invoices', ['status'=>'overdue'], 'id=? AND tenant_id=?', [$inv['id'], $t]);
            if (class_exists('Notifications')) {
                Notifications::add('Invoice ' . $inv['number'] . ' is overdue',
                    ['icon'=>'credit-card','url'=>SLATE_URL.'/plugins/clientdesk/admin/invoices.php']);
            }
        }
        // Expire quotes past valid_until.
        Database::query("UPDATE clientdesk_quotes SET status='expired'
            WHERE tenant_id=? AND status='sent' AND valid_until IS NOT NULL AND valid_until < CURDATE()", [$t]);
        // Deadline reminders: projects due within 3 days, not complete.
        $soon = Database::rows(
            "SELECT id, title FROM clientdesk_projects
              WHERE tenant_id=? AND phase!='complete' AND deadline IS NOT NULL
                AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)", [$t]);
        foreach ($soon as $p) {
            if (class_exists('Notifications')) {
                Notifications::add('Deadline approaching: ' . $p['title'],
                    ['icon'=>'calendar','url'=>SLATE_URL.'/plugins/clientdesk/admin/project.php?id='.$p['id']]);
            }
        }
    }

    /** When a customer registers, auto-link any client row with the same email. */
    public function onCustomerRegistered(int $customerId): void {
        try {
            $linkedId = ClientDeskAPI::autoLinkByEmail($customerId);
            if ($linkedId !== null) {
                AuditLog::record('clientdesk.portal_auto_linked', 'client#' . $linkedId, ['customer_id' => $customerId]);
            }
        } catch (\Throwable $e) {
            slate_log('ClientDesk auto-link failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Bind a completed Stripe checkout back to its invoice. */
    public function onStripeEvent(array $event): void {
        if (($event['type'] ?? '') !== 'checkout.session.completed') return;
        $session = $event['data']['object'] ?? [];
        $meta    = $session['metadata'] ?? [];
        if (($meta['source_plugin'] ?? '') !== 'clientdesk') return;
        $invoiceId = (int)($meta['invoice_id'] ?? 0);
        if ($invoiceId <= 0) return;
        Database::update('clientdesk_invoices',
            ['status'=>'paid','paid_at'=>slate_db_now(),
             'payment_ref'=>(string)($session['id'] ?? ''), 'payment_method'=>'stripe'],
            'id=? AND tenant_id=?', [$invoiceId, current_tenant_id()]);
        if (class_exists('Notifications')) {
            Notifications::add('Invoice paid online', ['icon'=>'credit-card',
                'url'=>SLATE_URL.'/plugins/clientdesk/admin/invoices.php']);
        }
    }

    /* ---------------- migrations ---------------- */

    private function runMigrations(string $from): void {
        // Step 1 — new tables via versioned .sql files.
        $dir = $this->dir('migrations');
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            if ($files) {
                sort($files);
                foreach ($files as $file) {
                    $target = basename($file, '.sql');
                    if (version_compare($target, $from, '>')) $this->runSqlFile($file, $target);
                }
            }
        }
        // Step 2 — additive columns (idempotent), portable across MySQL/MariaDB.
        $this->ensureColumn('clientdesk_clients',  'tags',           'VARCHAR(255) NULL AFTER access_token');
        $this->ensureColumn('clientdesk_invoices', 'payment_ref',    'VARCHAR(120) NULL AFTER paid_at');
        $this->ensureColumn('clientdesk_invoices', 'payment_method', 'VARCHAR(40) NULL AFTER payment_ref');
    }

    private function runSqlFile(string $file, string $tag): void {
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') continue;
            try { Database::query($stmt); }
            catch (\Throwable $e) { slate_log("ClientDesk migration {$tag}: " . $e->getMessage(), 'error'); }
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]);
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("ClientDesk ensureColumn {$table}.{$column}: " . $e->getMessage(), 'error');
        }
    }
}
