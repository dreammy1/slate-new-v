<?php
/**
 * Shop — dashboard overview.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
if (!Auth::can('shop.view_orders') && !Auth::can('shop.manage_products')) {
    Auth::requirePerm('shop.view_orders'); // triggers 403
}

$pageTitle  = __('shop', 'Shop');
$currentNav = 'shop';

$tid      = current_tenant_id();
$currency = Database::setting('shop.currency') ?: 'USD';

// Date range: default to last 30 days
$from = date('Y-m-d', strtotime('-29 days'));
$to   = date('Y-m-d');

$summary = ShopAPI::revenueSummary($from, $to);
$top     = ShopAPI::topProducts(5, $from, $to);
$recent  = ShopAPI::recentOrders(10);

// KPIs this period vs previous period
$prevFrom = date('Y-m-d', strtotime('-59 days'));
$prevTo   = date('Y-m-d', strtotime('-30 days'));
$prevSummary = ShopAPI::revenueSummary($prevFrom, $prevTo);

function revChange(float $current, float $prev): string {
    if ($prev == 0) return '';
    $pct = round((($current - $prev) / $prev) * 100, 1);
    $cls = $pct >= 0 ? 'text-success' : 'text-danger';
    $arrow = $pct >= 0 ? '↑' : '↓';
    return "<span class=\"{$cls} text-sm\">{$arrow} " . abs($pct) . "%</span>";
}

/**
 * Check whether the public storefront is reachable at /shop/.
 *
 * Returns true if reachable OR if we can't tell. Returns false only
 * when we get a confirmed 404 (or similar) from the server. This
 * keeps the setup banner from showing a false positive on hosts that
 * block loopback HTTP, run behind a firewall, or have other quirky
 * configs that prevent the self-check from completing.
 *
 * Cached for 60s on success, 15s on confirmed failure (so the user
 * can iterate quickly while fixing the .htaccess).
 */
function shop_storefront_reachable(): bool {
    $cached = Database::setting('shop.storefront_check');
    if ($cached) {
        $parts = explode('|', $cached, 2);
        if (count($parts) === 2) {
            $age   = time() - (int)$parts[0];
            $state = $parts[1];
            $ttl   = $state === 'ok' ? 60 : 15;
            if ($age < $ttl) return $state === 'ok';
        }
    }

    $url = rtrim(SLATE_URL, '/') . '/shop/';
    $reachable = true; // default to "we can't tell" → don't show the banner

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Slate-Shop-Setup-Check',
        ]);
        curl_exec($ch);
        $code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            // Connect failure / timeout / DNS. Can't tell — leave reachable=true.
            $reachable = true;
        } else {
            // We got a real HTTP response. 200-399 is reachable; 404 means
            // rewrite isn't set up; 503 means router was reached but the
            // plugin errored (still reachable from a routing standpoint).
            $reachable = ($code >= 200 && $code < 400) || $code === 503;
        }
    }

    Database::setSetting('shop.storefront_check', time() . '|' . ($reachable ? 'ok' : 'fail'));
    return $reachable;
}

/**
 * Check whether the v1.1.0 schema is fully applied. Returns a list of
 * missing pieces (empty list = all good).
 *
 * The migration handles most installs cleanly, but on shared hosts where
 * the MySQL user lacks ALTER TABLE privilege, the column-add step is
 * silently skipped — leaving the new pages broken in confusing ways.
 * This check surfaces the problem clearly with actionable next steps.
 */
function shop_schema_problems(): array {
    $missing = [];
    try {
        $tables = ['shop_categories', 'shop_product_variants',
                   'shop_carts', 'shop_cart_items'];
        foreach ($tables as $t) {
            $exists = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$t]
            );
            if ($exists === 0) $missing[] = "table `{$t}`";
        }
        $cols = ['featured', 'category_id', 'gallery_urls'];
        foreach ($cols as $c) {
            $exists = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shop_products'
                    AND COLUMN_NAME = ?",
                [$c]
            );
            if ($exists === 0) $missing[] = "column `shop_products.{$c}`";
        }
    } catch (\Throwable $e) {
        slate_log('shop_schema_problems check failed: ' . $e->getMessage(), 'error');
        return ['(could not verify schema)'];
    }
    return $missing;
}

// Allow manual re-check of the storefront via ?recheck=1, or schema retry
// via ?recheck=schema. The schema retry clears the cached version-applied
// flag so Shop::boot() will run the migration again on next request.
if (isset($_GET['recheck'])) {
    Database::setSetting('shop.storefront_check', '');
    if ($_GET['recheck'] === 'schema') {
        // Reset the version flag so boot() retries the migration. We don't
        // re-run it here directly because boot has already run for this
        // request — we redirect, and boot picks it up on the next one.
        Database::setSetting('shop.applied_version', '0.0.0');
    }
    header('Location: ' . plugin_url('shop', 'admin/index.php'));
    exit;
}

require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop')],
]); ?>

<?php
$schemaIssues = shop_schema_problems();
if (!empty($schemaIssues)): ?>
<div class="card" style="border-left: 4px solid var(--danger); margin-bottom: 1.5rem;">
    <div class="card-header">
        <h2 style="color: var(--danger);">⚠ Database upgrade incomplete</h2>
        <span class="badge badge-warning">Action needed</span>
    </div>
    <p class="text-sub" style="margin-bottom: 0.75rem;">
        The Shop plugin tried to upgrade your database schema but couldn't
        complete it. This is usually because the MySQL user in your
        <code>.env</code> doesn't have <code>ALTER TABLE</code> privilege
        — common on shared hosts.
    </p>
    <p class="text-sub" style="margin-bottom: 0.75rem;">
        <strong>Missing:</strong>
    </p>
    <ul style="margin: 0 0 12px; padding-left: 22px; font-family: var(--font-mono); font-size: 13px;">
        <?php foreach ($schemaIssues as $issue): ?>
            <li><?= e($issue) ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="text-sub text-sm" style="margin-bottom: 0.5rem;">
        <strong>Fix option 1 — run SQL by hand (recommended for shared hosts):</strong>
        Open phpMyAdmin (in your hosting control panel), select your Slate
        database, go to the SQL tab, and run the statements below. They are
        idempotent — safe to re-run if a piece is already in place.
    </p>
    <pre style="background: var(--surface-2); padding: 12px 14px;
                border-radius: var(--radius-sm); overflow: auto;
                font-family: var(--font-mono); font-size: 12px;
                line-height: 1.5; margin: 0 0 12px; max-height: 280px;"><?= e(implode("\n", [
        "-- Tables (safe to re-run thanks to IF NOT EXISTS)",
        "CREATE TABLE IF NOT EXISTS shop_categories (",
        "    id INT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL DEFAULT 1,",
        "    slug VARCHAR(100) NOT NULL, name VARCHAR(100) NOT NULL,",
        "    description TEXT NULL, image_url VARCHAR(500) NULL,",
        "    display_order INT NOT NULL DEFAULT 0,",
        "    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,",
        "    PRIMARY KEY (id), UNIQUE KEY tenant_slug (tenant_id, slug)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "CREATE TABLE IF NOT EXISTS shop_product_variants (",
        "    id INT UNSIGNED NOT NULL AUTO_INCREMENT, product_id INT UNSIGNED NOT NULL,",
        "    attribute VARCHAR(50) NOT NULL, value VARCHAR(100) NOT NULL,",
        "    sku VARCHAR(100) NOT NULL DEFAULT '', price_diff DECIMAL(10,2) NOT NULL DEFAULT 0.00,",
        "    stock_qty INT NOT NULL DEFAULT 0, display_order INT NOT NULL DEFAULT 0,",
        "    PRIMARY KEY (id), KEY product_id (product_id),",
        "    UNIQUE KEY product_value (product_id, attribute, value)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "CREATE TABLE IF NOT EXISTS shop_carts (",
        "    id INT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL DEFAULT 1,",
        "    session_id VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,",
        "    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,",
        "    PRIMARY KEY (id), UNIQUE KEY tenant_session (tenant_id, session_id)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "CREATE TABLE IF NOT EXISTS shop_cart_items (",
        "    id INT UNSIGNED NOT NULL AUTO_INCREMENT, cart_id INT UNSIGNED NOT NULL,",
        "    product_id INT UNSIGNED NOT NULL, variant_id INT UNSIGNED NULL,",
        "    qty INT UNSIGNED NOT NULL DEFAULT 1, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,",
        "    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,",
        "    PRIMARY KEY (id), KEY cart_id (cart_id)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "-- Columns on shop_products. These will error if the column already",
        "-- exists; that's fine, you can skip those errors.",
        "ALTER TABLE shop_products ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status;",
        "ALTER TABLE shop_products ADD COLUMN category_id INT UNSIGNED NULL AFTER category;",
        "ALTER TABLE shop_products ADD COLUMN gallery_urls TEXT NULL AFTER image_url;",
        "ALTER TABLE shop_products ADD KEY tenant_category_id (tenant_id, category_id);",
        "ALTER TABLE shop_products ADD KEY tenant_slug (tenant_id, slug);",
    ])) ?></pre>
    <p class="text-sub text-sm" style="margin-bottom: 0;">
        <strong>Fix option 2 — grant ALTER privilege:</strong>
        If your host lets you grant database privileges, give your Slate
        MySQL user <code>ALTER</code>. Then reload this page — the plugin
        will retry the upgrade automatically. ·
        <a href="?recheck=schema" class="btn btn-sm" style="margin-left:8px;">Re-check now</a>
    </p>
</div>
<?php endif; ?>

<?php if (!shop_storefront_reachable()):
    // Detect whether Slate is installed at the domain root or in a
    // subdirectory. Two sources, in priority order:
    //
    //   (1) $_SERVER['SCRIPT_NAME'] — ground truth from Apache. This is the
    //       URL path Apache actually used to serve THIS request. If we're
    //       at /slate/plugins/shop/admin/index.php, the install lives at
    //       /slate/. This is right even if .env's APP_URL is wrong.
    //
    //   (2) Fall back to parsing SLATE_URL — only used if SCRIPT_NAME is
    //       somehow unavailable (CLI, etc.).
    //
    // We surface a warning when (1) and (2) disagree, because that means
    // APP_URL in .env is misconfigured — emails, canonical URLs, OAuth
    // callbacks, etc. will all be broken even if the storefront works.

    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    // Trim the plugin-specific suffix to get Slate's install path
    $installPath = preg_replace('#/plugins/shop/admin/[^/]+\.php$#', '', $scriptPath);
    $installPath = rtrim((string)$installPath, '/');

    $envPath = rtrim(parse_url(SLATE_URL, PHP_URL_PATH) ?: '', '/');

    // Prefer SCRIPT_NAME-derived path; fall back to SLATE_URL's path
    $slatePath = $installPath !== '' ? $installPath : $envPath;
    $rewriteBase = $slatePath === '' ? '/' : $slatePath . '/';
    $envMismatch = ($installPath !== '' && $envPath !== '' && $installPath !== $envPath)
                || ($installPath !== '' && $envPath === '');

    $snippet = <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase {$rewriteBase}

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d

    RewriteRule ^shop/?\$               plugins/shop/storefront/router.php?_path= [L,QSA]
    RewriteRule ^shop/(.+)\$            plugins/shop/storefront/router.php?_path=\$1 [L,QSA]
</IfModule>
HTACCESS;
?>
<div class="card" style="border-left: 4px solid var(--warning); margin-bottom: 1.5rem;">
    <div class="card-header">
        <h2 style="color: var(--warning);">⚠ Storefront not reachable</h2>
        <span class="badge badge-warning">Setup needed</span>
    </div>
    <p class="text-sub" style="margin-bottom: 0.75rem;">
        Your <code><?= e(SLATE_URL) ?>/shop/</code> URL returns 404. The storefront
        is installed, but Apache doesn't know how to route public URLs to it yet.
        Add the snippet below to your Slate root <code>.htaccess</code> file:
    </p>
    <pre style="background: var(--surface-2); padding: 12px 14px;
                border-radius: var(--radius-sm); overflow: auto;
                font-family: var(--font-mono); font-size: 13px;
                line-height: 1.5; margin: 0 0 12px;"><?= e($snippet) ?></pre>
    <?php if ($envMismatch): ?>
    <div style="background: rgba(184,74,62,0.08); border: 1px solid rgba(184,74,62,0.25);
                border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 12px;
                font-size: 13px; color: #8F362C;">
        <strong>Also fix your <code>.env</code>:</strong>
        Slate is being served from <code><?= e($installPath ?: '/') ?></code> but your
        <code>APP_URL</code> setting is <code><?= e(SLATE_URL) ?></code>. These should match.
        Edit <code>.env</code> (same directory as <code>config.php</code>) and set:
        <code style="display:inline-block; padding:2px 6px; background:var(--surface-2);
                     border-radius:4px; margin-top:4px;">APP_URL=<?= e((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . $installPath) ?></code>
        Otherwise email links, OAuth callbacks, and other absolute-URL features will be broken.
    </div>
    <?php endif; ?>
    <p class="text-sub text-sm" style="margin: 0;">
        <strong>Where:</strong> Your root <code>.htaccess</code> lives in the same
        directory as <code>config.php</code> and the <code>admin/</code> folder.
        Paste the block above anywhere after the existing <code>AddDefaultCharset UTF-8</code>
        line, save, then reload this page. ·
        <a href="?recheck=1" class="btn btn-sm" style="margin-left:8px;">Re-check now</a>
    </p>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><?= __('shop', 'Shop') ?></h1>
        <p class="page-header-sub">Last 30 days &mdash; <?= e($from) ?> to <?= e($to) ?></p>
    </div>
    <div class="flex gap-2">
        <?php if (Auth::can('shop.manage_products')): ?>
        <a href="<?= e(plugin_url('shop', 'admin/products.php')) ?>?action=new" class="btn btn-primary">
            + <?= __('shop_add_product', 'Add Product') ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('shop.manage_orders')): ?>
        <a href="<?= e(plugin_url('shop', 'admin/orders.php')) ?>?action=new" class="btn">
            + <?= __('shop_add_order', 'New Order') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="flex gap-2" style="flex-wrap:wrap; margin-bottom:1.5rem;">
    <div class="card" style="flex:1; min-width:160px;">
        <div class="text-muted text-sm"><?= __('shop_revenue', 'Revenue') ?></div>
        <div style="font-size:1.6rem; font-weight:700; margin:0.25rem 0;">
            <?= e(ShopAPI::formatMoney((float)($summary['revenue'] ?? 0), $currency)) ?>
        </div>
        <?= revChange((float)($summary['revenue'] ?? 0), (float)($prevSummary['revenue'] ?? 0)) ?>
    </div>
    <div class="card" style="flex:1; min-width:160px;">
        <div class="text-muted text-sm"><?= __('shop_orders_count', 'Orders') ?></div>
        <div style="font-size:1.6rem; font-weight:700; margin:0.25rem 0;">
            <?= (int)($summary['order_count'] ?? 0) ?>
        </div>
        <?= revChange((float)($summary['order_count'] ?? 0), (float)($prevSummary['order_count'] ?? 0)) ?>
    </div>
    <div class="card" style="flex:1; min-width:160px;">
        <div class="text-muted text-sm"><?= __('shop_avg_order', 'Avg. Order') ?></div>
        <div style="font-size:1.6rem; font-weight:700; margin:0.25rem 0;">
            <?= e(ShopAPI::formatMoney((float)($summary['avg_order'] ?? 0), $currency)) ?>
        </div>
    </div>
    <div class="card" style="flex:1; min-width:160px;">
        <?php
        $pendingCnt = (int) Database::value(
            "SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ? AND status IN ('pending','processing')",
            [$tid]
        );
        ?>
        <div class="text-muted text-sm"><?= __('shop_pending', 'Pending / Processing') ?></div>
        <div style="font-size:1.6rem; font-weight:700; margin:0.25rem 0;"><?= $pendingCnt ?></div>
        <?php if ($pendingCnt > 0): ?>
            <a href="<?= e(plugin_url('shop', 'admin/orders.php')) ?>?status=pending"
               class="text-sm"><?= __('shop_view', 'View') ?> →</a>
        <?php endif; ?>
    </div>
</div>

<div class="flex gap-2" style="flex-wrap:wrap; align-items:flex-start;">

    <!-- Recent orders -->
    <div style="flex:2; min-width:280px;">
        <div class="card">
            <div class="card-header">
                <h2><?= __('shop_recent_orders', 'Recent Orders') ?></h2>
                <a href="<?= e(plugin_url('shop', 'admin/orders.php')) ?>" class="btn btn-sm btn-ghost">
                    <?= __('shop_view_all', 'View all') ?>
                </a>
            </div>
            <?php if (empty($recent)): ?>
                <div class="empty">
                    <div class="empty-title">No orders yet</div>
                    <p>Orders will appear here once customers start purchasing.</p>
                </div>
            <?php else: ?>
                <div class="data-list" data-single-open>
                    <?php foreach ($recent as $o):
                        ob_start(); ?>
                        <a href="<?= e(plugin_url('shop', 'admin/orders.php')) ?>?view=<?= (int)$o['id'] ?>"
                           class="btn btn-sm">View</a>
                        <?php $actions = ob_get_clean();
                        slate_data_row([
                            'avatar'       => mb_strtoupper(mb_substr($o['customer_name'], 0, 2)),
                            'avatar_color' => ShopAPI::statusBadgeClass($o['status']),
                            'title'        => $o['order_number'] . ' — ' . $o['customer_name'],
                            'badge'        => [$o['status'], str_replace('badge-', '', ShopAPI::statusBadgeClass($o['status']))],
                            'meta'         => ShopAPI::formatMoney((float)$o['total'], $o['currency']),
                            'detail'       => [
                                'Order #'  => $o['order_number'],
                                'Customer' => $o['customer_email'],
                                'Total'    => ShopAPI::formatMoney((float)$o['total'], $o['currency']),
                                'Status'   => $o['status'],
                                'Date'     => $o['created_at'],
                            ],
                            'actions' => $actions,
                        ]);
                    endforeach; ?>
                </div>
                <?php slate_data_list_script(); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top products -->
    <div style="flex:1; min-width:220px;">
        <div class="card">
            <div class="card-header">
                <h2><?= __('shop_top_products', 'Top Products') ?></h2>
            </div>
            <?php if (empty($top)): ?>
                <p class="text-muted text-sm">No sales yet in this period.</p>
            <?php else: ?>
                <?php foreach ($top as $p): ?>
                    <div class="flex items-center gap-2" style="padding:0.5rem 0; border-bottom:1px solid var(--border);">
                        <div style="flex:1;">
                            <div><?= e($p['product_name']) ?></div>
                            <div class="text-muted text-sm"><?= (int)$p['qty_sold'] ?> sold</div>
                        </div>
                        <div style="font-weight:600;">
                            <?= e(ShopAPI::formatMoney((float)$p['revenue'], $currency)) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Low-stock alert -->
        <?php
        $lowStockProducts = Database::rows(
            "SELECT id, name, stock_qty FROM shop_products
              WHERE tenant_id = ? AND manage_stock = 1 AND stock_qty <= 5 AND status = 'active'
              ORDER BY stock_qty ASC LIMIT 5",
            [$tid]
        );
        ?>
        <?php if (!empty($lowStockProducts)): ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header">
                <h2><?= __('shop_low_stock', 'Low Stock') ?></h2>
                <span class="badge badge-warning"><?= count($lowStockProducts) ?></span>
            </div>
            <?php foreach ($lowStockProducts as $lp): ?>
                <div class="flex items-center gap-2" style="padding:0.4rem 0; border-bottom:1px solid var(--border);">
                    <div style="flex:1;" class="text-sm"><?= e($lp['name']) ?></div>
                    <span class="badge <?= (int)$lp['stock_qty'] === 0 ? 'badge-danger' : 'badge-warning' ?>">
                        <?= (int)$lp['stock_qty'] ?> left
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require $root . '/admin/partials/footer.php'; ?>
