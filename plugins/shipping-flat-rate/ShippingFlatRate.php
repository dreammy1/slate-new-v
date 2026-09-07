<?php
/**
 * Flat Rate Shipping — bootstrap.
 *
 * Three responsibilities:
 *
 *  1. Ensure the schema is current (creates shippingflatrate_boxes
 *     on first activation, seeds it with USPS Flat Rate defaults,
 *     and adds the shipping_box_slug column to shop_products).
 *
 *  2. Register admin nav under Shop.
 *
 *  3. Hook into Shop's `shop_shipping_rate` filter. When a
 *     cart's shipping is being calculated, we pack the cart's
 *     items into the cheapest combination of boxes and return
 *     the total price.
 *
 * The actual algorithm is in ShippingAPI; this class is just the
 * plugin scaffolding. See ShippingAPI::packCart() for the packer
 * and ShippingAPI::priceForCart() for the public entry point.
 */

class ShippingFlatRate extends Plugin {

    public function boot(): void {
        $apiFile = $this->dir('ShippingAPI.php');
        if (file_exists($apiFile)) {
            require_once $apiFile;
        }

        $applied = $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version, '<')) {
            $this->runMigrations();
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
            }
        }

        Hook::addFilter('admin_nav_items',     [$this, 'addAdminNav']);
        Hook::addFilter('shop_shipping_rate',  [$this, 'calculateShipping'], 10, 2);

        // Warn if a second shipping plugin is active — both register the
        // same rate filter and only the first-loaded one takes effect.
        Hook::addFilter('admin_dashboard_widgets', [$this, 'conflictWidget']);

        // Product edit form: append a "Ships in" dropdown to the
        // Shipping & dimensions card, and accept the submitted value
        // when the product is saved.
        Hook::addFilter('shop_product_edit_shipping_fields',
            [$this, 'renderProductBoxField'], 10, 2);
        Hook::addFilter('shop_product_db_row',
            [$this, 'mapProductBoxField'], 10, 2);

        // Cart page: append a small visible breakdown of which boxes
        // are being charged for, so customers can see (and admins can
        // verify) the plugin's calculation.
        Hook::addFilter('shop_cart_shipping_breakdown',
            [$this, 'renderCartShippingBreakdown'], 10, 2);
    }

    // ── Admin nav ─────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('shipping_flat_rate.manage')) return $items;
        if (!PluginLoader::isActive('shop'))         return $items;

        $items[] = [
            'slug'   => 'shop-shipping-boxes',
            'label'  => __('shipping_boxes', 'Shipping boxes'),
            'href'   => $this->url('admin/boxes.php'),
            'icon'   => 'package',
            'perm'   => 'shipping_flat_rate.manage',
            'order'  => 517,
            'parent' => 'shop',
        ];
        return $items;
    }

    // ── Conflict notice ───────────────────────────────────────

    /**
     * Both this plugin and `flat-rate-shipping` (weight tiers) register the
     * `shop_shipping_rate` filter at the same priority. When both are active,
     * only the one loaded first ever computes a rate — the other is silently
     * ignored. Surface that on the dashboard so an admin can deactivate one.
     */
    public function conflictWidget(array $widgets): array {
        if (!PluginLoader::isActive('flat-rate-shipping')) return $widgets;
        if (!Auth::isSuperAdmin() && !Auth::can('shipping_flat_rate.manage')) return $widgets;

        $pluginsUrl = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/plugins.php';
        ob_start(); ?>
        <div class="card">
            <div class="card-header"><h2>Shipping plugins conflict</h2></div>
            <div class="alert alert-warning" role="status" style="margin:0;">
                Two shipping plugins are active — <strong>Flat Rate Shipping (boxes)</strong>
                and <strong>Flat-rate shipping by weight</strong>. They both compute the cart
                shipping rate, so only the one loaded first takes effect and the other is
                silently ignored. Deactivate one on
                <a href="<?= e($pluginsUrl) ?>">Plugins</a> to make pricing predictable.
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Hook callback ─────────────────────────────────────────

    /**
     * Filter `shop_shipping_rate` (registered by Shop v1.2.6+).
     *
     * Contract: receive the currently-claimed rate ($current) and a
     * $context array (['subtotal' => float, 'items' => array,
     * 'billing' => array]). Return either:
     *
     *   - A numeric value to CLAIM this calculation (we packed the
     *     cart and decided the rate is $X).
     *   - $current unchanged (null if no prior plugin claimed it,
     *     or another plugin's value) to ABSTAIN — Shop's free-
     *     shipping threshold runs FIRST so we don't have to handle
     *     it, and Shop's flat-rate fallback runs if every plugin
     *     abstains.
     *
     * We abstain when:
     *   - No active boxes are configured (no way to compute)
     *   - The cart has no items (empty cart — let Shop handle the
     *     edge case)
     *   - An earlier-registered plugin (lower priority number) has
     *     already claimed it ($current is numeric)
     */
    public function calculateShipping($current, array $context) {
        // Don't override another plugin's claim.
        if (is_numeric($current)) return $current;

        if (!class_exists('ShippingAPI')) return $current;
        $items = $context['items'] ?? [];
        if (empty($items)) return $current;

        // Returns null when no active boxes exist — we abstain and
        // Shop's flat-rate fallback takes over.
        $price = ShippingAPI::priceForCart($items);
        return $price === null ? $current : $price;
    }

    /**
     * Filter `shop_product_edit_shipping_fields`. Append a "Ships in"
     * dropdown to the product edit form's shipping card.
     */
    public function renderProductBoxField(string $html, array $editing): string {
        if (!class_exists('ShippingAPI')) return $html;
        $boxes = ShippingAPI::activeBoxes();
        if (empty($boxes)) return $html;

        $current = (string)($editing['shipping_box_slug'] ?? '');

        ob_start(); ?>
        <div class="field">
            <label class="field-label" for="shipping_box_slug">
                Ships in
            </label>
            <select id="shipping_box_slug" name="shipping_box_slug">
                <option value="">— Default (smallest active box) —</option>
                <?php foreach ($boxes as $b): ?>
                    <option value="<?= e($b['slug']) ?>"
                            <?= $current === $b['slug'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                        ($<?= number_format((float)$b['price'], 2) ?>,
                        holds ~<?= (int)$b['capacity'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="field-hint">
                Which box this product ships in. The cart packer charges
                a fixed price per box; smaller items can ride along free
                in a larger box that's already required.
            </div>
        </div>
        <?php
        return $html . ob_get_clean();
    }

    /**
     * Filter `shop_product_db_row`. Inject shipping_box_slug into the
     * row before insert/update. Empty string maps to NULL so the
     * unique-default-box fallback applies.
     */
    public function mapProductBoxField(array $row, array $data): array {
        $raw = trim((string)($_POST['shipping_box_slug'] ?? ''));
        $row['shipping_box_slug'] = $raw === '' ? null : $raw;
        return $row;
    }

    /**
     * Filter `shop_cart_shipping_breakdown`. Append a small
     * human-readable summary of which boxes are being charged. Shown
     * directly under the Shipping line on the cart page.
     *
     * Examples:
     *   "Ships in: 1 Medium Box ($17.10)"
     *   "Ships in: 2 Padded Envelopes ($21.70)"
     *   "Ships in: 1 Large Box + 1 Medium Box ($39.90)"
     *
     * If we can't pack the cart (no items, no boxes), return the
     * empty string so nothing is rendered.
     */
    public function renderCartShippingBreakdown(string $html, array $cart): string {
        if (!class_exists('ShippingAPI')) return $html;
        $items = $cart['items'] ?? [];
        if (empty($items)) return $html;

        $pack = ShippingAPI::packCart($items);
        if ($pack === null || empty($pack['boxes'])) return $html;

        // Build a comma-joined list of "<count> × <box name>" segments.
        // For a 1-count we omit the multiplier ("1 Medium Box" not
        // "1 × Medium Box") because that reads more naturally.
        $segments = [];
        foreach ($pack['boxes'] as $row) {
            $count = (int)$row['count'];
            $name  = (string)$row['box']['name'];
            $label = $count === 1 ? $name : "$count × $name";
            $segments[] = $label;
        }
        $list = implode(' + ', $segments);

        // Inline-styled to match the existing sf-summary-line look but
        // dimmed; we don't want this overpowering the price totals.
        ob_start(); ?>
        <div class="sf-summary-line sf-shipping-breakdown"
             style="font-size:12px; color:var(--sf-muted, #6b7280); margin-top:-6px; padding-top:0;">
            <span>Ships in: <?= e($list) ?></span>
            <span></span>
        </div>
        <?php
        return $html . ob_get_clean();
    }

    // ── Schema ────────────────────────────────────────────────

    private function runMigrations(): void {
        // Create the boxes table if it doesn't exist.
        try {
            Database::query("
                CREATE TABLE IF NOT EXISTS shippingflatrate_boxes (
                    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenant_id   INT UNSIGNED NOT NULL DEFAULT 1,
                    slug        VARCHAR(64)  NOT NULL,
                    name        VARCHAR(120) NOT NULL,
                    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    capacity    INT UNSIGNED NOT NULL DEFAULT 1,
                    sort_order  INT NOT NULL DEFAULT 100,
                    is_active   TINYINT(1) NOT NULL DEFAULT 1,
                    notes       TEXT NULL,
                    created_at  DATETIME NOT NULL,
                    updated_at  DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_tenant_slug (tenant_id, slug),
                    KEY idx_active_sort (is_active, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            slate_log('shipping-flat-rate migration (create boxes) failed: '
                . $e->getMessage(), 'error');
            return;
        }

        // v1.0.2 — multi-tenant isolation. Existing single-tenant installs
        // created the table without tenant_id and with a globally-unique
        // slug; add the column (existing rows default to tenant 1) and
        // swap the unique key to (tenant_id, slug). All idempotent.
        $this->ensureColumn('shippingflatrate_boxes', 'tenant_id',
            'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id');
        try {
            $hasOld = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shippingflatrate_boxes'
                    AND INDEX_NAME = 'uniq_slug'");
            if ($hasOld > 0) {
                Database::query("ALTER TABLE shippingflatrate_boxes DROP INDEX uniq_slug");
            }
            $hasNew = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shippingflatrate_boxes'
                    AND INDEX_NAME = 'uniq_tenant_slug'");
            if ($hasNew === 0) {
                Database::query("ALTER TABLE shippingflatrate_boxes
                                 ADD UNIQUE KEY uniq_tenant_slug (tenant_id, slug)");
            }
        } catch (\Throwable $e) {
            slate_log('shipping-flat-rate migration (tenant unique key) failed: '
                . $e->getMessage(), 'warning');
        }

        // Seed default USPS Flat Rate boxes if the table is empty.
        // Prices reflect USPS Retail Flat Rate as of early 2026 — the
        // admin should adjust them to whatever USPS charges them
        // (Commercial Plus, regional rates, etc).
        try {
            $tid   = current_tenant_id();
            $count = (int)Database::value(
                "SELECT COUNT(*) FROM shippingflatrate_boxes WHERE tenant_id = ?", [$tid]);
            if ($count === 0) {
                $now = slate_db_now();
                $seeds = [
                    ['envelope',     'USPS Padded Flat Rate Envelope',  10.85, 2,  10],
                    ['small',        'USPS Small Flat Rate Box',         10.40, 3,  20],
                    ['medium',       'USPS Medium Flat Rate Box',        17.10, 6,  30],
                    ['large',        'USPS Large Flat Rate Box',         22.80, 12, 40],
                ];
                foreach ($seeds as $s) {
                    Database::insert('shippingflatrate_boxes', [
                        'tenant_id'  => $tid,
                        'slug'       => $s[0],
                        'name'       => $s[1],
                        'price'      => $s[2],
                        'capacity'   => $s[3],
                        'sort_order' => $s[4],
                        'is_active'  => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            slate_log('shipping-flat-rate migration (seed boxes) failed: '
                . $e->getMessage(), 'warning');
        }

        // Add shipping_box_slug column to shop_products. This is a
        // shop-plugin table but we're allowed to add a column we own —
        // the column is OUR data and we drop it on uninstall.
        // (Actually we don't drop it — see uninstall.sql comment.)
        $this->ensureColumn('shop_products', 'shipping_box_slug',
            "VARCHAR(64) NULL DEFAULT NULL");
    }

    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("shipping-flat-rate ensureColumn $table.$column: "
                . $e->getMessage(), 'error');
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            $tablesExists = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shippingflatrate_boxes'"
            );
            if ($tablesExists === 0) return false;

            $tenantCol = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shippingflatrate_boxes'
                    AND COLUMN_NAME = 'tenant_id'"
            );
            if ($tenantCol === 0) return false;

            $colExists = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shop_products'
                    AND COLUMN_NAME = 'shipping_box_slug'"
            );
            return $colExists > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
