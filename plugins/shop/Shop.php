<?php
/**
 * Shop — WooCommerce-style e-commerce plugin for Slate.
 *
 * Manages products with variants and categories, orders, customers,
 * coupons, and revenue reports. Provides a public storefront with
 * cart and checkout.
 *
 * v1.1.0:
 *   - Categories
 *   - Single-axis product variants
 *   - CSV import/export
 *   - Public storefront (/shop)
 *   - Product gallery
 */

class Shop extends Plugin {

    public function boot(): void {
        // Load the public API so other plugins can call it
        $apiFile = $this->dir('ShopAPI.php');
        if (file_exists($apiFile)) {
            require_once $apiFile;
        }

        // Run schema migrations if needed. We only mark the version as
        // applied after verifying the expected tables/columns exist —
        // otherwise a partial failure (logged-but-suppressed inside
        // runMigrations) would leave us thinking we're upgraded when
        // we aren't, and the user would be stuck with 500s on the new
        // pages forever.
        $applied = $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version, '<')) {
            $this->runMigrations($applied);
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
            }
        }

        // Sidebar nav items
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);

        // Dashboard widget
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);

        // Dashboard "Products" listing widget
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addProductsDashboardWidget']);

        // Content Builder integration: a "Shop" block for embedding the
        // storefront into any page. Boot order isn't guaranteed, so register
        // now if Content Builder already booted, else catch its hook.
        $this->registerContentBlock();
    }

    /** Register the "Shop" block with Content Builder (order-independent). */
    private function registerContentBlock(): void {
        $register = function ($registry) {
            $opts = ShopAPI::pickerOptions();
            // Full product grid (image + price + add-to-cart).
            $registry::register('shop', [
                'label'  => __('shop_block', 'Product grid'),
                'group'  => 'Shop',
                'fields' => [
                    ['key'=>'source','type'=>'select','label'=>'Show','options'=>$opts],
                    ['key'=>'limit','type'=>'text','label'=>'Max products','placeholder'=>'12'],
                    ['key'=>'columns','type'=>'select','label'=>'Columns','options'=>[
                        ['v'=>'2','l'=>'Two'],['v'=>'3','l'=>'Three'],['v'=>'4','l'=>'Four'],
                    ]],
                ],
                'render'   => ['ShopAPI', 'renderContentBlock'],
                'defaults' => ['source' => '', 'limit' => '12', 'columns' => '3'],
            ]);
            // Compact list (thumb + name + price) — for sidebars / narrow columns.
            $registry::register('product-list', [
                'label'  => __('shop_list_block', 'Product list (compact)'),
                'group'  => 'Shop',
                'fields' => [
                    ['key'=>'heading','type'=>'text','label'=>'Heading (optional)','placeholder'=>'Featured products'],
                    ['key'=>'source','type'=>'select','label'=>'Show','options'=>$opts],
                    ['key'=>'limit','type'=>'text','label'=>'Max products','placeholder'=>'5'],
                ],
                'render'   => ['ShopAPI', 'renderCompactList'],
                'defaults' => ['heading' => 'Featured products', 'source' => '', 'limit' => '5'],
            ]);
        };

        if (class_exists('BlockRegistry')) {
            $register('BlockRegistry');                       // CB already booted
        } else {
            Hook::addAction('content_register_blocks', $register); // CB boots later
        }
    }

    // ──────────────────────────────────────────────────────
    // Navigation
    // ──────────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('shop.view_orders') && !Auth::can('shop.manage_products')) {
            return $items;
        }
        $items[] = ['slug' => 'shop',           'label' => __('shop', 'Shop'),
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'shopping-bag', 'order' => 510, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-orders',    'label' => __('shop_orders', 'Orders'),
                    'href' => $this->url('admin/orders.php'),
                    'icon' => 'clipboard-list', 'perm' => 'shop.view_orders',
                    'order' => 511, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-products',  'label' => __('shop_products', 'Products'),
                    'href' => $this->url('admin/products.php'),
                    'icon' => 'package', 'perm' => 'shop.manage_products',
                    'order' => 512, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-categories','label' => __('shop_categories', 'Categories'),
                    'href' => $this->url('admin/categories.php'),
                    'icon' => 'folder', 'perm' => 'shop.manage_products',
                    'order' => 513, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-customers', 'label' => __('shop_customers', 'Customers'),
                    'href' => $this->url('admin/customers.php'),
                    'icon' => 'users', 'perm' => 'shop.view_orders',
                    'order' => 514, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-coupons',   'label' => __('shop_coupons', 'Coupons'),
                    'href' => $this->url('admin/coupons.php'),
                    'icon' => 'tag', 'perm' => 'shop.manage_coupons',
                    'order' => 515, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-reports',   'label' => __('shop_reports', 'Reports'),
                    'href' => $this->url('admin/reports.php'),
                    'icon' => 'bar-chart-2', 'perm' => 'shop.view_reports',
                    'order' => 516, 'group' => 'shop'];
        return $items;
    }

    // ──────────────────────────────────────────────────────
    // Dashboard widget
    // ──────────────────────────────────────────────────────

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('shop.view_orders') && !Auth::can('shop.view_reports')) {
            return $widgets;
        }

        $tid = current_tenant_id();
        $currency = Database::setting('shop.currency') ?: 'USD';

        $todayRevenue = (float) Database::value(
            "SELECT COALESCE(SUM(total), 0) FROM shop_orders
              WHERE tenant_id = ? AND status = 'completed'
                AND DATE(created_at) = CURDATE()",
            [$tid]
        );
        $pendingCount = (int) Database::value(
            "SELECT COUNT(*) FROM shop_orders
              WHERE tenant_id = ? AND status IN ('pending','processing')",
            [$tid]
        );
        $productCount = (int) Database::value(
            "SELECT COUNT(*) FROM shop_products WHERE tenant_id = ? AND status = 'active'",
            [$tid]
        );
        $lowStock = (int) Database::value(
            "SELECT COUNT(*) FROM shop_products
              WHERE tenant_id = ? AND manage_stock = 1 AND stock_qty <= 5 AND status = 'active'",
            [$tid]
        );
        $latestOrders = Database::rows(
            "SELECT id, order_number, customer_name, customer_email, total, currency, status, created_at
               FROM shop_orders WHERE tenant_id = ? ORDER BY id DESC LIMIT 5", [$tid]);

        $ordersUrl = $this->url('admin/orders.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('shop', 'Shop') ?></h2>
                <a href="<?= e($ordersUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_today', "Today's Revenue") ?></div>
                    <div class="dwidget-kpi-v"><?= e(ShopAPI::formatMoney($todayRevenue, $currency)) ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_pending', 'Pending orders') ?></div>
                    <div class="dwidget-kpi-v"><?= $pendingCount ?></div>
                </div>
            </div>
            <?php if ($latestOrders): ?>
                <div class="dlist">
                    <?php foreach ($latestOrders as $o):
                        $name   = trim((string)($o['customer_name'] ?? '')) ?: (string)($o['customer_email'] ?? '');
                        $colors = ['completed' => 'success', 'processing' => 'info', 'pending' => 'warning',
                                   'on-hold' => 'muted', 'cancelled' => 'danger', 'refunded' => 'muted', 'failed' => 'danger'];
                        slate_dlist_row([
                            'avatar'       => $name !== '' ? $name : '#',
                            'avatar_color' => $colors[$o['status']] ?? 'muted',
                            'title'        => $name !== '' ? $name : __('shop_guest', 'Guest'),
                            'sub'          => '#' . ($o['order_number'] ?? $o['id']) . ' · ' . ucfirst(str_replace('-', ' ', (string)$o['status'])),
                            'amount'       => ShopAPI::formatMoney((float)$o['total'], (string)($o['currency'] ?: $currency)),
                            'time'         => $o['created_at'] ? date('M j', strtotime($o['created_at'])) : '',
                            'href'         => $ordersUrl . '?view=' . (int)$o['id'],
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('shop_no_orders', 'No orders yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /** Dashboard widget: a compact list of recent products + stock KPIs. */
    public function addProductsDashboardWidget(array $widgets): array {
        if (!Auth::can('shop.manage_products')
            && !Auth::can('shop.view_orders')
            && !Auth::can('shop.view_reports')) {
            return $widgets;
        }
        $tid = current_tenant_id();
        $currency = Database::setting('shop.currency') ?: 'USD';
        $productsUrl = $this->url('admin/products.php');

        try {
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM shop_products WHERE tenant_id = ? AND status = 'active'", [$tid]);
            $lowStock = (int) Database::value(
                "SELECT COUNT(*) FROM shop_products
                  WHERE tenant_id = ? AND manage_stock = 1 AND stock_qty <= 5 AND status = 'active'", [$tid]);
            $recent = Database::rows(
                "SELECT id, name, sku, price, sale_price, stock_qty, manage_stock
                   FROM shop_products
                  WHERE tenant_id = ? AND status = 'active'
                  ORDER BY id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('shop_products', 'Products') ?></h2>
                <a href="<?= e($productsUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_active_products', 'Active products') ?></div>
                    <div class="dwidget-kpi-v"><?= $active ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_low_stock', 'Low stock') ?></div>
                    <div class="dwidget-kpi-v"><?= $lowStock ?></div>
                </div>
            </div>
            <?php if ($recent): ?>
                <div class="dlist">
                    <?php foreach ($recent as $p):
                        $hasSale = !empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price'];
                        $price   = $hasSale ? (float)$p['sale_price'] : (float)$p['price'];
                        $managed = !empty($p['manage_stock']);
                        $stock   = $managed ? ((int)$p['stock_qty'] . ' in stock') : 'In stock';
                        $low     = $managed && (int)$p['stock_qty'] <= 5;
                        slate_dlist_row([
                            'avatar'       => (string)$p['name'],
                            'avatar_color' => $low ? 'warning' : 'muted',
                            'title'        => (string)$p['name'],
                            'sub'          => trim((string)($p['sku'] ?? '')) !== '' ? (string)$p['sku'] : $stock,
                            'amount'       => ShopAPI::formatMoney($price, $currency),
                            'time'         => $stock,
                            'href'         => $productsUrl . '?edit=' . (int)$p['id'],
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('shop_no_products', 'No products yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ──────────────────────────────────────────────────────
    // Migrations helper
    // ──────────────────────────────────────────────────────

    private function runMigrations(string $from): void {
        // Step 1: Run the .sql migration files (plain CREATE TABLE etc.)
        $dir = $this->dir('migrations');
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            if ($files) {
                sort($files);
                foreach ($files as $file) {
                    $target = basename($file, '.sql');
                    if (version_compare($target, $from, '>')) {
                        $this->runSqlFile($file, $target);
                    }
                }
            }
        }

        // Step 2: PHP-driven schema additions (idempotent column adds + index
        // adds + data backfill). Splitting these out of the .sql file makes
        // the migration portable across MySQL/MariaDB versions and avoids the
        // stored-procedure tricks that don't survive PDO's statement-splitter.
        if (version_compare('1.1.0', $from, '>')) {
            $this->ensureColumn('shop_products', 'featured',
                'TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
            $this->ensureColumn('shop_products', 'category_id',
                'INT UNSIGNED NULL AFTER category');
            $this->ensureColumn('shop_products', 'gallery_urls',
                'TEXT NULL AFTER image_url');
            $this->ensureIndex('shop_products', 'tenant_category_id',
                '(tenant_id, category_id)');
            $this->ensureIndex('shop_products', 'tenant_slug',
                '(tenant_id, slug)');

            // Backfill slugs in PHP — works on MySQL versions without
            // REGEXP_REPLACE (pre-8.0) or matching MariaDB builds.
            try {
                $rows = Database::rows(
                    "SELECT id, name FROM shop_products
                      WHERE (slug IS NULL OR slug = '') AND name IS NOT NULL"
                );
                foreach ($rows as $r) {
                    $s = strtolower((string)$r['name']);
                    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
                    $s = trim($s, '-');
                    if ($s === '') $s = 'product-' . (int)$r['id'];
                    Database::update('shop_products', ['slug' => $s], 'id = ?', [(int)$r['id']]);
                }
            } catch (\Throwable $e) {
                slate_log('Shop slug backfill failed: ' . $e->getMessage(), 'error');
            }
        }

        // v1.2.0 — additional product fields (dimensions, GTIN)
        if (version_compare('1.2.0', $from, '>')) {
            $this->ensureColumn('shop_products', 'dimensions_l',
                'DECIMAL(8,2) NULL AFTER weight');
            $this->ensureColumn('shop_products', 'dimensions_w',
                'DECIMAL(8,2) NULL AFTER dimensions_l');
            $this->ensureColumn('shop_products', 'dimensions_h',
                'DECIMAL(8,2) NULL AFTER dimensions_w');
            $this->ensureColumn('shop_products', 'gtin',
                'VARCHAR(50) NULL AFTER sku');
        }

        // v1.2.1 — per-variant image and description. Imported variants
        // need editable fields beyond attribute/value/sku/price/stock,
        // and admins want to ship distinct visuals + copy per variant
        // (e.g., a coffee blend's flavor-specific tasting notes and
        // packaging photo). Both nullable so existing variants are
        // unaffected.
        if (version_compare('1.2.1', $from, '>')) {
            $this->ensureColumn('shop_product_variants', 'image_url',
                'VARCHAR(500) NULL AFTER stock_qty');
            $this->ensureColumn('shop_product_variants', 'description',
                'TEXT NULL AFTER image_url');
        }

        // v1.2.6 — security: unguessable capability token for the order
        // confirmation URL. Before this fix, /shop/order?key=<order_number>
        // used the sequential order_number (e.g. ORD-00001) as the auth
        // key, so anyone could enumerate orders by walking the integer
        // suffix and read customer PII (name, email, shipping address).
        // We add `view_token` (32 hex chars = 128 bits of entropy from
        // random_bytes) and switch the storefront lookup to use it. The
        // admin still displays order_number for humans.
        if (version_compare('1.2.6', $from, '>')) {
            $this->ensureColumn('shop_orders', 'view_token',
                'CHAR(32) NULL AFTER order_number');
            $this->ensureIndex('shop_orders', 'view_token_idx', '(view_token)');
            // Backfill existing rows. We do this in PHP so the migration
            // is portable across MySQL versions and so we can use
            // cryptographic-quality randomness (DB-side UUID() is fine
            // but not random_bytes-strong on every MariaDB build).
            try {
                $rows = Database::rows(
                    "SELECT id FROM shop_orders
                      WHERE view_token IS NULL OR view_token = ''"
                );
                foreach ($rows as $r) {
                    Database::update('shop_orders',
                        ['view_token' => bin2hex(random_bytes(16))],
                        'id = ?', [(int)$r['id']]
                    );
                }
            } catch (\Throwable $e) {
                slate_log('Shop view_token backfill failed: ' . $e->getMessage(), 'error');
            }
        }

        // v1.2.7 — strip HTML tags from existing product names.
        // CSV imports from WooCommerce-style exports sometimes carry
        // <b>/<strong>/<em> tags inside the product name itself for
        // visual emphasis. Cart and storefront defensively HTML-escape
        // names (to prevent XSS), so tagged names display as literal
        // text like "Spice Mix <b>SPICY</b>". The CSV importer in
        // 1.2.7+ strips tags on the way in; this backfill cleans up
        // historical rows. Idempotent: re-running it on already-clean
        // rows is a no-op.
        if (version_compare('1.2.7', $from, '>')) {
            try {
                // Find rows whose name contains an HTML tag. The LIKE
                // pattern '%<%>%' matches "anything < anything > anything".
                $rows = Database::rows(
                    "SELECT id, name FROM shop_products
                      WHERE name LIKE '%<%>%'"
                );
                $cleaned = 0;
                foreach ($rows as $r) {
                    $clean = html_entity_decode(
                        strip_tags((string)$r['name']),
                        ENT_QUOTES | ENT_HTML5, 'UTF-8'
                    );
                    $clean = trim(preg_replace('/\s+/u', ' ', $clean));
                    if ($clean !== '' && $clean !== $r['name']) {
                        Database::update('shop_products',
                            ['name' => $clean],
                            'id = ?', [(int)$r['id']]
                        );
                        $cleaned++;
                    }
                }
                if ($cleaned > 0) {
                    slate_log("Shop 1.2.7 migration: cleaned HTML tags from {$cleaned} product names", 'info');
                }
            } catch (\Throwable $e) {
                slate_log('Shop product-name cleanup failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * Execute a .sql file statement-by-statement. Errors are logged but
     * don't halt — most failures are "already exists" idempotency
     * conditions that are safe to ignore.
     */
    private function runSqlFile(string $file, string $tag): void {
        $sql = (string)file_get_contents($file);
        // Strip line comments
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                Database::query($stmt);
            } catch (\Throwable $e) {
                slate_log("Shop migration {$tag} statement error: " . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Add a column if it doesn't already exist. Skips silently otherwise.
     * Definition is the part that follows the column name in ALTER TABLE
     * ADD COLUMN (e.g. "VARCHAR(100) NOT NULL DEFAULT ''").
     */
    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Shop ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Add a non-unique index if it doesn't already exist. column_spec is
     * the parenthesized column list, e.g. "(tenant_id, slug)".
     */
    private function ensureIndex(string $table, string $indexName, string $columnSpec): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $indexName]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD KEY `{$indexName}` {$columnSpec}");
            }
        } catch (\Throwable $e) {
            slate_log("Shop ensureIndex {$table}.{$indexName} failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Verify v1.1.0's schema is fully in place. Used to decide whether
     * it's safe to mark applied_version=1.1.0. If this returns false,
     * we'll keep retrying the migration on each request.
     */
    private function schemaIsCurrent(): bool {
        try {
            $tables = ['shop_categories', 'shop_product_variants',
                       'shop_carts', 'shop_cart_items'];
            foreach ($tables as $t) {
                $exists = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$t]
                );
                if ((int)$exists === 0) return false;
            }
            $cols = ['featured', 'category_id', 'gallery_urls',
                     'dimensions_l', 'dimensions_w', 'dimensions_h', 'gtin'];
            foreach ($cols as $c) {
                $exists = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'shop_products'
                        AND COLUMN_NAME = ?",
                    [$c]
                );
                if ((int)$exists === 0) return false;
            }
            // v1.2.1 variant columns
            $variantCols = ['image_url', 'description'];
            foreach ($variantCols as $c) {
                $exists = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'shop_product_variants'
                        AND COLUMN_NAME = ?",
                    [$c]
                );
                if ((int)$exists === 0) return false;
            }
            // v1.2.6 — order view_token (unguessable confirmation key)
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shop_orders'
                    AND COLUMN_NAME = 'view_token'"
            );
            if ((int)$exists === 0) return false;
            return true;
        } catch (\Throwable $e) {
            slate_log('Shop schemaIsCurrent check failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
