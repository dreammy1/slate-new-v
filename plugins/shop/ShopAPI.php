<?php
/**
 * Shop public API.
 *
 * Other plugins call these static methods instead of touching shop tables
 * directly. Keep these signatures stable across minor versions.
 *
 * Usage:
 *   if (PluginLoader::isActive('shop') && class_exists('ShopAPI')) {
 *       $orders = ShopAPI::recentOrders(10);
 *   }
 */

class ShopAPI {

    // ══════════════════════════════════════════════════════
    // PRODUCTS
    // ══════════════════════════════════════════════════════

    /** List active products, optionally filtered by category. */
    public static function products(string $category = '', int $limit = 50): array {
        $tid  = current_tenant_id();
        $args = [$tid];
        $where = "tenant_id = ? AND status = 'active'";
        if ($category !== '') {
            $where .= " AND category = ?";
            $args[] = $category;
        }
        return Database::rows(
            "SELECT id, sku, name, slug, price, sale_price, stock_qty,
                    category, image_url, short_desc
               FROM shop_products
              WHERE {$where}
              ORDER BY name ASC
              LIMIT " . max(1, min(500, $limit)),
            $args
        );
    }

    /** Get a single product by id. */
    public static function product(int $id): ?array {
        $row = Database::row(
            "SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
        return $row ?: null;
    }

    /** Get effective price (sale_price if set, otherwise price). */
    public static function effectivePrice(array $product): float {
        if ($product['sale_price'] !== null && (float)$product['sale_price'] > 0) {
            return (float)$product['sale_price'];
        }
        return (float)$product['price'];
    }

    /** Decrement stock. Returns false if insufficient stock. */
    public static function decrementStock(int $productId, int $qty = 1): bool {
        $tid = current_tenant_id();
        $p   = Database::row(
            "SELECT name, manage_stock, stock_qty FROM shop_products WHERE id = ? AND tenant_id = ?",
            [$productId, $tid]
        );
        if (!$p) return false;
        if (!$p['manage_stock']) return true;  // unmanaged stock always ok
        $oldQty = (int)$p['stock_qty'];
        if ($oldQty < $qty) return false;
        $newQty = $oldQty - $qty;
        Database::update(
            'shop_products',
            ['stock_qty' => $newQty],
            'id = ? AND tenant_id = ?',
            [$productId, $tid]
        );
        // Notify once, on the crossing into "low stock" (matches the
        // dashboard widget's own <= 5 threshold) — not on every sale
        // after that, so the bell doesn't spam once a product runs low.
        if ($newQty <= 5 && $oldQty > 5 && class_exists('Notifications')) {
            Notifications::add('Low stock · ' . $p['name'], [
                'body' => 'Only ' . $newQty . ' left in stock.',
                'url'  => function_exists('plugin_url') ? plugin_url('shop', 'admin/product.php') . '?id=' . $productId : '',
                'icon' => 'alert-triangle',
            ]);
        }
        return true;
    }

    /**
     * Decrement variant stock specifically. Variants always have stock
     * tracking (the column is non-null), so there's no manage_stock
     * flag to check.
     */
    public static function decrementVariantStock(int $variantId, int $qty = 1): bool {
        $v = Database::row("SELECT stock_qty FROM shop_product_variants WHERE id = ?", [$variantId]);
        if (!$v) return false;
        $new = max(0, (int)$v['stock_qty'] - $qty);
        Database::update(
            'shop_product_variants',
            ['stock_qty' => $new],
            'id = ?',
            [$variantId]
        );
        return (int)$v['stock_qty'] >= $qty;
    }

    // ══════════════════════════════════════════════════════
    // ORDERS
    // ══════════════════════════════════════════════════════

    /**
     * Create a new order with line items.
     *
     * $items  = [['product_id'=>1,'qty'=>2], ...]
     * Returns the new order id, or throws \RuntimeException on failure.
     */
    public static function createOrder(
        string $customerEmail,
        string $customerName,
        array  $items,
        array  $opts = []
    ): int {
        $tid      = current_tenant_id();
        $currency = Database::setting('shop.currency') ?: 'USD';
        $taxRate  = (float)(Database::setting('shop.tax_rate') ?: 0);

        // Validate and resolve items. Items may include an optional
        // variant_id; when present we use the variant's price + sku and
        // decrement variant stock instead of product stock at the end.
        $lineItems   = [];
        $subtotal    = 0.0;
        foreach ($items as $item) {
            $pid       = (int)($item['product_id'] ?? 0);
            $qty       = max(1, (int)($item['qty'] ?? 1));
            $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;

            $p = self::product($pid);
            if (!$p) throw new \RuntimeException("Product #$pid not found.");

            $variant = null;
            if ($variantId !== null) {
                $variant = self::variant($variantId);
                if (!$variant || (int)$variant['product_id'] !== $pid) {
                    throw new \RuntimeException("Variant #$variantId is not valid for product #$pid.");
                }
            }

            // Use explicit unit_price if caller provided one (so cart's
            // already-priced lines survive); otherwise compute it.
            $price = isset($item['unit_price'])
                   ? (float)$item['unit_price']
                   : self::variantPrice($p, $variant);
            $sku   = $variant && !empty($variant['sku']) ? $variant['sku'] : $p['sku'];
            $name  = $variant
                   ? $p['name'] . ' — ' . $variant['attribute'] . ': ' . $variant['value']
                   : $p['name'];

            $lineTotal   = $price * $qty;
            $subtotal   += $lineTotal;
            $lineItems[] = [
                'product_id'   => $pid,
                'product_sku'  => $sku,
                'product_name' => mb_substr($name, 0, 255),
                'quantity'     => $qty,
                'unit_price'   => $price,
                'line_total'   => $lineTotal,
                // not persisted but used for stock decrement below
                '_variant_id'  => $variantId,
            ];
        }

        // Coupon
        $discountTotal = 0.0;
        $couponCode    = $opts['coupon_code'] ?? '';
        if ($couponCode !== '') {
            $coupon = self::applyCoupon($couponCode, $subtotal);
            if ($coupon !== null) {
                $discountTotal = $coupon['discount'];
            }
        }

        // Shipping. Build a minimal "billing" context for shipping plugins
        // from the address options on this order — USPS-like plugins use
        // the destination ZIP/country to compute live rates.
        $shippingContext = [
            'address_1' => $opts['ship_address_1'] ?? '',
            'address_2' => $opts['ship_address_2'] ?? '',
            'city'      => $opts['ship_city']      ?? '',
            'state'     => $opts['ship_state']     ?? '',
            'postcode'  => $opts['ship_postcode']  ?? '',
            'country'   => $opts['ship_country']   ?? '',
        ];
        $shippingTotal = (float)($opts['shipping_total']
            ?? self::calculateShipping($subtotal - $discountTotal, $lineItems, $shippingContext));

        // Tax + total — identical math to cartTotals()/the Stripe charge, so
        // reconcilePaidAmount() never parks a correctly-priced order on-hold.
        $t             = self::computeTotals($subtotal, $discountTotal, $shippingTotal, $taxRate);
        $discountTotal = $t['discount'];
        $taxTotal      = $t['tax'];
        $total         = $t['total'];

        // Order number is derived from the row's own auto-increment id
        // (assigned below), not COUNT(*)+1 — that collided under concurrency
        // and reused a number after a delete. We insert a unique temporary
        // value first, then stamp the final PREFIX-<id> inside the same
        // transaction, so no external reader ever sees the placeholder.
        $orderPrefix  = Database::setting('shop.order_prefix') ?: 'ORD';
        $tempOrderNum = 'TMP-' . bin2hex(random_bytes(12));

        // Resolve or create customer
        $customerId = $opts['customer_id'] ?? self::findOrCreateCustomerId($customerEmail, $customerName);

        // Wrap order create + line items + stock decrement + coupon increment
        // in a single transaction. Without this, a mid-write failure leaves
        // partial data (orphaned order row, items but no stock decrement, etc).
        // The Hook::doAction call fires AFTER commit so subscribers always see
        // a fully-persisted order, never one that's about to roll back.
        $pdo = Database::get();
        $startedTx = false;
        try {
            // Defensive: don't try to start a nested transaction if a caller
            // upstream is already inside one. PDO would throw.
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }

            $orderId = Database::insert('shop_orders', [
                'tenant_id'       => $tid,
                'order_number'    => $tempOrderNum,
                'view_token'      => bin2hex(random_bytes(16)),
                'customer_id'     => $customerId,
                'customer_email'  => mb_substr($customerEmail, 0, 190),
                'customer_name'   => mb_substr($customerName, 0, 255),
                'status'          => $opts['status'] ?? 'pending',
                'subtotal'        => $subtotal,
                'discount_total'  => $discountTotal,
                'shipping_total'  => $shippingTotal,
                'tax_total'       => $taxTotal,
                'total'           => $total,
                'currency'        => $currency,
                'payment_method'  => $opts['payment_method'] ?? null,
                'coupon_code'     => $couponCode !== '' ? $couponCode : null,
                'notes'           => $opts['notes'] ?? null,
                'ship_address_1'  => $opts['ship_address_1'] ?? null,
                'ship_address_2'  => $opts['ship_address_2'] ?? null,
                'ship_city'       => $opts['ship_city'] ?? null,
                'ship_state'      => $opts['ship_state'] ?? null,
                'ship_postcode'   => $opts['ship_postcode'] ?? null,
                'ship_country'    => $opts['ship_country'] ?? null,
            ]);

            // Stamp the final, collision-free order number from the id.
            $orderNumber = $orderPrefix . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
            Database::update('shop_orders', ['order_number' => $orderNumber], 'id = ?', [$orderId]);

            // Insert line items (strip internal _variant_id used only for stock routing)
            foreach ($lineItems as $li) {
                unset($li['_variant_id']);
                $li['order_id'] = $orderId;
                Database::insert('shop_order_items', $li);
            }

            // Decrement stock — variant stock when a variant was selected,
            // otherwise the product's own stock.
            foreach ($items as $item) {
                $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $qty = (int)($item['qty'] ?? 1);
                if ($vid !== null) {
                    self::decrementVariantStock($vid, $qty);
                } else {
                    self::decrementStock((int)$item['product_id'], $qty);
                }
            }

            // Increment coupon usage
            if ($couponCode !== '') {
                Database::query(
                    "UPDATE shop_coupons SET uses = uses + 1
                      WHERE tenant_id = ? AND code = ?",
                    [$tid, $couponCode]
                );
            }

            if ($startedTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Hook::doAction('shop_order_created', $orderId, $customerEmail);

        if (class_exists('Notifications')) {
            Notifications::add('New order · ' . $orderNumber, [
                'body' => ($customerName !== '' ? $customerName : 'A customer') . ' placed an order for '
                        . $currency . ' ' . number_format($total, 2),
                'url'  => function_exists('plugin_url') ? plugin_url('shop', 'admin/order.php') . '?id=' . $orderId : '',
                'icon' => 'shopping-cart',
            ]);
        }

        return $orderId;
    }

    /** Get a single order with its line items. */
    public static function order(int $id): ?array {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM shop_orders WHERE id = ? AND tenant_id = ?",
            [$id, $tid]
        );
        if (!$order) return null;
        $order['items'] = Database::rows(
            "SELECT * FROM shop_order_items WHERE order_id = ?",
            [$id]
        );
        return $order;
    }

    /** Recent orders. */
    public static function recentOrders(int $limit = 20, string $status = ''): array {
        $tid  = current_tenant_id();
        $args = [$tid];
        $where = 'tenant_id = ?';
        if ($status !== '') {
            $where .= ' AND status = ?';
            $args[] = $status;
        }
        return Database::rows(
            "SELECT id, order_number, customer_email, customer_name,
                    status, total, currency, created_at
               FROM shop_orders
              WHERE {$where}
              ORDER BY created_at DESC
              LIMIT " . max(1, min(200, $limit)),
            $args
        );
    }

    /** Update order status. */
    public static function updateStatus(int $orderId, string $status): bool {
        $allowed = ['pending','processing','on-hold','completed','cancelled','refunded'];
        if (!in_array($status, $allowed, true)) return false;
        $rows = Database::update(
            'shop_orders',
            ['status' => $status],
            'id = ? AND tenant_id = ?',
            [$orderId, current_tenant_id()]
        );
        if ($rows > 0) {
            Hook::doAction('shop_order_status_changed', $orderId, $status);
        }
        return $rows > 0;
    }

    /**
     * Reconcile an order's recorded total against the amount the payment
     * gateway actually captured. The Stripe completion paths rebuild the
     * order from the live cart, which can drift from what the customer was
     * charged if the cart changed between intent creation and payment. We
     * never reject a paid order — the money is already taken — but a mismatch
     * is parked on 'on-hold' for manual review instead of auto-advancing to
     * 'processing', and the discrepancy is logged + audited.
     *
     * @param int    $paidCents     Amount captured by the gateway, in cents.
     * @param string $paidCurrency  Gateway currency (ISO, e.g. 'USD'); '' skips the currency check.
     * @return array{ok:bool,order_cents:int,paid_cents:int,reason:?string}
     */
    public static function reconcilePaidAmount(int $orderId, int $paidCents, string $paidCurrency = ''): array {
        $order = self::order($orderId);
        if (!$order) {
            return ['ok' => false, 'order_cents' => 0, 'paid_cents' => $paidCents, 'reason' => 'order_missing'];
        }

        $orderCents = (int) round((float)$order['total'] * 100);
        $reason = null;
        if ($paidCurrency !== '' && strtoupper($paidCurrency) !== strtoupper((string)$order['currency'])) {
            $reason = 'currency_mismatch';
        } elseif ($paidCents !== $orderCents) {
            $reason = 'amount_mismatch';
        }

        if ($reason === null) {
            return ['ok' => true, 'order_cents' => $orderCents, 'paid_cents' => $paidCents, 'reason' => null];
        }

        $note = sprintf(
            '[%s] Payment reconciliation %s: gateway charged %d, order total %d (%s). Held for review.',
            date('Y-m-d H:i'), $reason, $paidCents, $orderCents,
            strtoupper($paidCurrency !== '' ? $paidCurrency : (string)$order['currency'])
        );
        $existing = trim((string)($order['notes'] ?? ''));
        Database::update('shop_orders',
            ['notes' => trim($existing . "\n" . $note), 'status' => 'on-hold'],
            'id = ? AND tenant_id = ?', [$orderId, current_tenant_id()]);

        slate_log("Shop order #$orderId payment $reason: charged $paidCents vs order $orderCents", 'error');
        if (class_exists('AuditLog')) {
            AuditLog::record('shop.payment_mismatch', (string)$orderId,
                ['reason' => $reason, 'paid_cents' => $paidCents, 'order_cents' => $orderCents]);
        }
        Hook::doAction('shop_order_status_changed', $orderId, 'on-hold');

        if (class_exists('Notifications')) {
            Notifications::add('Payment needs review · ' . $order['order_number'], [
                'body' => 'Gateway charged ' . number_format($paidCents / 100, 2)
                        . ' but the order totals ' . number_format($orderCents / 100, 2)
                        . ' (' . $reason . '). Order held on-hold.',
                'url'  => function_exists('plugin_url') ? plugin_url('shop', 'admin/order.php') . '?id=' . $orderId : '',
                'icon' => 'alert-triangle',
            ]);
        }

        return ['ok' => false, 'order_cents' => $orderCents, 'paid_cents' => $paidCents, 'reason' => $reason];
    }

    // ══════════════════════════════════════════════════════
    // COUPONS
    // ══════════════════════════════════════════════════════

    /**
     * Validate and apply a coupon to a subtotal.
     * Returns ['discount' => float, 'coupon' => array] or null if invalid.
     */
    public static function applyCoupon(string $code, float $subtotal): ?array {
        $tid    = current_tenant_id();
        $coupon = Database::row(
            "SELECT * FROM shop_coupons
              WHERE tenant_id = ? AND code = ? AND active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
                AND (max_uses IS NULL OR uses < max_uses)",
            [$tid, strtoupper(trim($code))]
        );
        if (!$coupon) return null;
        if ($coupon['min_order'] !== null && $subtotal < (float)$coupon['min_order']) {
            return null;
        }
        $discount = $coupon['type'] === 'percent'
            ? round($subtotal * ((float)$coupon['value'] / 100), 2)
            : min((float)$coupon['value'], $subtotal);
        return ['discount' => $discount, 'coupon' => $coupon];
    }

    /**
     * Single source of truth for order math. The cart view (cartTotals),
     * the Stripe charge amount (built from cartTotals), and the persisted
     * order (createOrder) must all agree, or reconcilePaidAmount() parks
     * paid orders on-hold. Tax applies to (subtotal − discount + shipping);
     * total = subtotal − discount + shipping + tax.
     */
    public static function computeTotals(float $subtotal, float $discount, float $shipping, float $taxRate): array {
        $subtotal = round($subtotal, 2);
        $discount = round(min(max($discount, 0.0), $subtotal), 2);
        $shipping = round($shipping, 2);
        $taxable  = $subtotal - $discount + $shipping;
        $tax      = round($taxable * ($taxRate / 100), 2);
        $total    = round($subtotal - $discount + $shipping + $tax, 2);
        return ['discount' => $discount, 'tax' => $tax, 'total' => $total];
    }

    // ══════════════════════════════════════════════════════
    // CUSTOMERS
    // ══════════════════════════════════════════════════════

    /** Find a customer by email, or null. */
    public static function findCustomer(string $email): ?array {
        $row = Database::row(
            "SELECT * FROM shop_customers WHERE tenant_id = ? AND email = ?",
            [current_tenant_id(), strtolower(trim($email))]
        );
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════
    // REPORTING
    // ══════════════════════════════════════════════════════

    /** Revenue summary for a date range. */
    public static function revenueSummary(string $from, string $to): array {
        $tid = current_tenant_id();
        return Database::row(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(total), 0) AS revenue,
                COALESCE(SUM(discount_total), 0) AS discounts,
                COALESCE(SUM(tax_total), 0) AS taxes,
                COALESCE(SUM(shipping_total), 0) AS shipping,
                COALESCE(AVG(total), 0) AS avg_order
               FROM shop_orders
              WHERE tenant_id = ?
                AND status IN ('processing','completed')
                AND DATE(created_at) BETWEEN ? AND ?",
            [$tid, $from, $to]
        ) ?: [];
    }

    /** Top-selling products by quantity. */
    public static function topProducts(int $limit = 10, string $from = '', string $to = ''): array {
        $tid  = current_tenant_id();
        $args = [$tid];
        $dateWhere = '';
        if ($from && $to) {
            $dateWhere = "AND DATE(o.created_at) BETWEEN ? AND ?";
            $args[] = $from;
            $args[] = $to;
        }
        return Database::rows(
            "SELECT oi.product_id, oi.product_name,
                    SUM(oi.quantity) AS qty_sold,
                    SUM(oi.line_total) AS revenue
               FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE o.tenant_id = ? AND o.status IN ('processing','completed')
                    {$dateWhere}
              GROUP BY oi.product_id, oi.product_name
              ORDER BY qty_sold DESC
              LIMIT " . max(1, min(100, $limit)),
            $args
        );
    }

    // ══════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════

    public static function formatMoney(float $amount, string $currency = ''): string {
        if ($currency === '') {
            $currency = Database::setting('shop.currency') ?: 'USD';
        }
        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£',
            'CAD' => 'CA$', 'AUD' => 'A$', 'JPY' => '¥',
            'BDT' => '৳',
        ];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    public static function statusBadgeClass(string $status): string {
        return match($status) {
            'completed'  => 'badge-active',
            'processing' => 'badge-installed',
            'pending'    => 'badge-warning',
            'on-hold'    => 'badge-inactive',
            'cancelled'  => 'badge-danger',
            'refunded'   => 'badge-muted',
            default      => 'badge-inactive',
        };
    }

    // ──────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────

    private static function findOrCreateCustomerId(string $email, string $name): int {
        $tid      = current_tenant_id();
        $email    = strtolower(trim($email));
        $existing = Database::value(
            "SELECT id FROM shop_customers WHERE tenant_id = ? AND email = ?",
            [$tid, $email]
        );
        if ($existing) return (int)$existing;

        $parts = explode(' ', trim($name), 2);
        return Database::insert('shop_customers', [
            'tenant_id'  => $tid,
            'email'      => $email,
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
        ]);
    }

    /**
     * Calculate the shipping charge for a cart.
     *
     * Hook point: plugins can register shipping methods via the
     * `shop_shipping_rate` filter, which receives:
     *   - $rate    float|null  Current rate (null = "no plugin has decided yet")
     *   - $context array       ['subtotal' => float, 'items' => array, 'billing' => array]
     * and returns the new rate (float) or null to fall through.
     *
     * Resolution order:
     *   1. Free-shipping threshold (if subtotal >= threshold → free, regardless of plugins)
     *   2. Plugin filter (first plugin to return non-null wins)
     *   3. Legacy hardcoded `shop.flat_shipping_rate` setting
     *
     * The free-shipping threshold runs FIRST so admins always have a
     * reliable "give the customer free shipping over $X" lever that
     * works no matter which shipping plugin is active.
     */
    public static function calculateShipping(float $subtotal, array $items = [], array $billing = []): float {
        // Step 1: free-shipping threshold
        $freeThreshold = (float)(Database::setting('shop.free_shipping_threshold') ?: 0);
        if ($freeThreshold > 0 && $subtotal >= $freeThreshold) return 0.0;

        // Step 2: ask plugins
        $context = ['subtotal' => $subtotal, 'items' => $items, 'billing' => $billing];
        $rate    = null;
        if (class_exists('Hook')) {
            $rate = Hook::applyFilters('shop_shipping_rate', $rate, $context);
        }
        if (is_numeric($rate)) {
            return max(0.0, (float)$rate);
        }

        // Step 3: legacy hardcoded fallback
        return (float)(Database::setting('shop.flat_shipping_rate') ?: 0);
    }

    // ══════════════════════════════════════════════════════
    // CATEGORIES
    // ══════════════════════════════════════════════════════

    public static function categories(bool $withCounts = false): array {
        $tid = current_tenant_id();
        if (!$withCounts) {
            return Database::rows(
                "SELECT * FROM shop_categories WHERE tenant_id = ?
                   ORDER BY display_order ASC, name ASC",
                [$tid]
            );
        }

        // Check whether shop_products has the category_id column before
        // attempting the correlated subquery. On hosts where the migration's
        // ALTER TABLE was silently skipped (missing ALTER privilege on shared
        // MySQL, for example), we still return categories — just with a
        // product_count of 0 — instead of fataling.
        $hasCategoryCol = false;
        try {
            $hasCategoryCol = (int)Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shop_products'
                    AND COLUMN_NAME = 'category_id'"
            ) > 0;
        } catch (\Throwable $e) { /* leave false */ }

        if ($hasCategoryCol) {
            return Database::rows(
                "SELECT c.*, (
                     SELECT COUNT(*) FROM shop_products p
                      WHERE p.tenant_id = c.tenant_id
                        AND p.category_id = c.id
                        AND p.status = 'active'
                 ) AS product_count
                   FROM shop_categories c
                  WHERE c.tenant_id = ?
                  ORDER BY c.display_order ASC, c.name ASC",
                [$tid]
            );
        }
        return Database::rows(
            "SELECT c.*, 0 AS product_count FROM shop_categories c
              WHERE c.tenant_id = ?
              ORDER BY c.display_order ASC, c.name ASC",
            [$tid]
        );
    }

    public static function category(int $id): ?array {
        $row = Database::row("SELECT * FROM shop_categories WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]);
        return $row ?: null;
    }

    public static function categoryBySlug(string $slug): ?array {
        $row = Database::row("SELECT * FROM shop_categories WHERE slug = ? AND tenant_id = ?",
            [$slug, current_tenant_id()]);
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════
    // PUBLIC-FACING PRODUCTS
    // ══════════════════════════════════════════════════════

    /** Lookup by slug. Used by storefront product pages. */
    public static function productBySlug(string $slug): ?array {
        $row = Database::row(
            "SELECT * FROM shop_products
              WHERE slug = ? AND tenant_id = ? AND status = 'active'",
            [$slug, current_tenant_id()]
        );
        return $row ?: null;
    }

    /** Public-safe listing for the storefront. */
    public static function publishedProducts(array $filters = [], int $limit = 24, int $offset = 0): array {
        $tid = current_tenant_id();
        $where = "tenant_id = ? AND status = 'active'";
        $args  = [$tid];

        if (!empty($filters['category_id'])) {
            $where .= " AND category_id = ?";
            $args[] = (int)$filters['category_id'];
        } elseif (!empty($filters['category_slug'])) {
            $cat = self::categoryBySlug($filters['category_slug']);
            if ($cat) {
                $where .= " AND category_id = ?";
                $args[] = (int)$cat['id'];
            } else {
                return [];
            }
        }
        if (!empty($filters['search'])) {
            $where .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $term  = '%' . $filters['search'] . '%';
            $args[] = $term; $args[] = $term; $args[] = $term;
        }
        if (!empty($filters['featured'])) {
            $where .= " AND featured = 1";
        }

        $order = match ($filters['sort'] ?? 'newest') {
            'price-asc'  => 'price ASC',
            'price-desc' => 'price DESC',
            'name'       => 'name ASC',
            'featured'   => 'featured DESC, created_at DESC',
            default      => 'created_at DESC',
        };

        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return Database::rows(
            "SELECT id, sku, name, slug, short_desc, price, sale_price,
                    stock_qty, manage_stock, image_url, featured, category_id,
                    (SELECT COUNT(*) FROM shop_product_variants v
                      WHERE v.product_id = shop_products.id) AS variant_count
               FROM shop_products
              WHERE {$where}
              ORDER BY {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $args
        );
    }

    public static function publishedProductCount(array $filters = []): int {
        $tid = current_tenant_id();
        $where = "tenant_id = ? AND status = 'active'";
        $args  = [$tid];
        if (!empty($filters['category_id'])) {
            $where .= " AND category_id = ?";
            $args[] = (int)$filters['category_id'];
        }
        if (!empty($filters['search'])) {
            $where .= " AND (name LIKE ? OR description LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $args[] = $term; $args[] = $term;
        }
        return (int)Database::value("SELECT COUNT(*) FROM shop_products WHERE {$where}", $args);
    }

    // ══════════════════════════════════════════════════════
    // VARIANTS
    // ══════════════════════════════════════════════════════

    public static function variantsFor(int $productId): array {
        return Database::rows(
            "SELECT * FROM shop_product_variants
              WHERE product_id = ?
              ORDER BY display_order ASC, id ASC",
            [$productId]
        );
    }

    public static function variant(int $variantId): ?array {
        $row = Database::row("SELECT * FROM shop_product_variants WHERE id = ?", [$variantId]);
        return $row ?: null;
    }

    /** Effective price for a product, factoring in selected variant. */
    public static function variantPrice(array $product, ?array $variant = null): float {
        $base = self::effectivePrice($product);
        if ($variant) {
            $base += (float)$variant['price_diff'];
        }
        return round(max(0, $base), 2);
    }

    // ══════════════════════════════════════════════════════
    // CART (session-based, guest-friendly)
    // ══════════════════════════════════════════════════════

    /** Returns the cart id for this session, creating one if needed. */
    public static function cartId(string $sessionId): int {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT id FROM shop_carts WHERE tenant_id = ? AND session_id = ?",
            [$tid, $sessionId]
        );
        if ($row) return (int)$row['id'];
        return Database::insert('shop_carts', [
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
        ]);
    }

    public static function cartItems(string $sessionId): array {
        $cartId = self::cartId($sessionId);
        return Database::rows(
            "SELECT ci.*, p.name AS product_name, p.slug AS product_slug,
                    p.image_url, p.stock_qty AS product_stock, p.manage_stock,
                    v.attribute AS variant_attribute, v.value AS variant_value
               FROM shop_cart_items ci
               JOIN shop_products p ON p.id = ci.product_id
          LEFT JOIN shop_product_variants v ON v.id = ci.variant_id
              WHERE ci.cart_id = ?
              ORDER BY ci.added_at ASC",
            [$cartId]
        );
    }

    public static function cartTotals(string $sessionId, string $couponCode = '', array $billing = []): array {
        $items    = self::cartItems($sessionId);
        $subtotal = 0.0;
        $itemCount = 0;
        foreach ($items as $i) {
            $subtotal  += (float)$i['unit_price'] * (int)$i['qty'];
            $itemCount += (int)$i['qty'];
        }
        $taxRate  = (float)(Database::setting('shop.tax_rate') ?: 0);

        // Optional coupon (caller-supplied so this stays a pure function of
        // its inputs). applyCoupon() returns null for invalid/expired codes
        // or an unmet minimum, in which case no discount applies.
        $discount    = 0.0;
        $couponLabel = '';
        $couponCode  = strtoupper(trim($couponCode));
        if ($couponCode !== '') {
            $c = self::applyCoupon($couponCode, $subtotal);
            if ($c !== null) {
                $discount    = (float)$c['discount'];
                $couponLabel = (string)($c['coupon']['code'] ?? $couponCode);
            } else {
                $couponCode = ''; // not applicable — don't echo it back as applied
            }
        }

        // Shipping is computed on the post-discount subtotal so free-shipping
        // thresholds behave predictably. Pass items + billing so weight-/
        // dimension-based plugins (flat-rate-shipping, USPS, etc.) have what
        // they need; billing is empty at cart-view time, present at checkout.
        $shipping = self::calculateShipping($subtotal - $discount, $items, $billing);

        // Same math as createOrder() — see computeTotals().
        $t = self::computeTotals($subtotal, $discount, $shipping, $taxRate);

        return [
            'items'         => $items,
            'item_count'    => $itemCount,
            'subtotal'      => round($subtotal, 2),
            'discount'      => $t['discount'],
            'coupon_code'   => $couponCode,
            'coupon_label'  => $couponLabel,
            'tax'           => $t['tax'],
            'tax_rate'      => $taxRate,
            'shipping'      => round($shipping, 2),
            'total'         => $t['total'],
        ];
    }

    public static function addToCart(string $sessionId, int $productId, int $qty = 1, ?int $variantId = null): array {
        $product = self::product($productId);
        if (!$product || $product['status'] !== 'active') {
            return ['ok' => false, 'error' => 'Product is not available.'];
        }

        $variant = null;
        if ($variantId !== null) {
            $variant = self::variant($variantId);
            if (!$variant || (int)$variant['product_id'] !== $productId) {
                return ['ok' => false, 'error' => 'Selected variant is not valid.'];
            }
        }

        $qty = max(1, min(999, $qty));

        // Stock check
        if ((int)$product['manage_stock']) {
            $available = $variant ? (int)$variant['stock_qty'] : (int)$product['stock_qty'];
            if ($available < $qty) {
                return ['ok' => false, 'error' => sprintf(
                    'Only %d available; you requested %d.', $available, $qty)];
            }
        }

        $price  = self::variantPrice($product, $variant);
        $cartId = self::cartId($sessionId);

        // Merge with existing line for same product+variant
        $existing = Database::row(
            "SELECT id, qty FROM shop_cart_items
              WHERE cart_id = ? AND product_id = ?
                AND " . ($variantId === null ? "variant_id IS NULL" : "variant_id = ?"),
            $variantId === null ? [$cartId, $productId] : [$cartId, $productId, $variantId]
        );

        if ($existing) {
            $newQty = (int)$existing['qty'] + $qty;
            Database::update('shop_cart_items',
                ['qty' => $newQty, 'unit_price' => $price],
                'id = ?', [$existing['id']]);
        } else {
            Database::insert('shop_cart_items', [
                'cart_id'    => $cartId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty'        => $qty,
                'unit_price' => $price,
            ]);
        }

        return ['ok' => true];
    }

    public static function updateCartQty(string $sessionId, int $cartItemId, int $qty): array {
        $cartId = self::cartId($sessionId);
        $item = Database::row("SELECT * FROM shop_cart_items WHERE id = ? AND cart_id = ?",
            [$cartItemId, $cartId]);
        if (!$item) return ['ok' => false, 'error' => 'Cart item not found.'];

        if ($qty <= 0) {
            Database::delete('shop_cart_items', 'id = ? AND cart_id = ?', [$cartItemId, $cartId]);
            return ['ok' => true];
        }
        $qty = min(999, $qty);
        Database::update('shop_cart_items', ['qty' => $qty], 'id = ? AND cart_id = ?',
            [$cartItemId, $cartId]);
        return ['ok' => true];
    }

    public static function removeCartItem(string $sessionId, int $cartItemId): void {
        $cartId = self::cartId($sessionId);
        Database::delete('shop_cart_items', 'id = ? AND cart_id = ?', [$cartItemId, $cartId]);
    }

    public static function clearCart(string $sessionId): void {
        $cartId = self::cartId($sessionId);
        Database::delete('shop_cart_items', 'cart_id = ?', [$cartId]);
    }

    /** Checkout: convert cart → order, clear cart on success. */
    /**
     * Return the list of registered payment providers, sorted by priority.
     *
     * Other plugins register payment providers via the `shop_payment_providers`
     * filter, returning the array with their provider appended. Each provider
     * is an array with:
     *
     *   id          string  Unique identifier (e.g. 'stripe', 'paypal')
     *   label       string  Human-readable name shown to customers
     *   description string  Short description shown under the label (optional)
     *   priority    int     Sort order (lower = first; default 100)
     *   available   bool    True if configured + usable; false hides it
     *   render_form callable():string  Optional extra HTML rendered inside
     *               the checkout form (e.g. card-element placeholder). Most
     *               redirect-based providers don't need this.
     *   handle_checkout callable(string $sid, array $billing):array
     *               Dispatch when this method is selected on place-order.
     *               Returns one of:
     *                 ['ok' => true, 'order_id' => N]     — synchronous
     *                 ['ok' => true, 'redirect'  => $url] — off-site
     *                 ['ok' => false, 'error' => $msg]
     *
     * Shop core always provides a 'manual' provider for offline / pay-later
     * orders. Plugins that want to OFFER nothing-to-pay shouldn't replace
     * 'manual' — they should leave it alone and add their own provider.
     */
    public static function paymentProviders(): array {
        $providers = Hook::applyFilters('shop_payment_providers', [
            [
                'id'          => 'manual',
                'label'       => __('payment_manual', 'Manual / Pay later'),
                'description' => __('payment_manual_desc',
                    'Place the order now; arrange payment with the seller.'),
                'priority'    => 1000,  // last by default
                'available'   => true,
                'render_form' => null,
                'handle_checkout' => function (string $sid, array $billing): array {
                    return self::checkoutCartManual($sid, $billing);
                },
            ],
        ]);

        // Filter out anything malformed or unavailable, then sort by priority.
        $clean = [];
        foreach ($providers as $p) {
            if (!is_array($p)) continue;
            if (empty($p['id']) || empty($p['label'])) continue;
            if (!is_callable($p['handle_checkout'] ?? null)) continue;
            if (empty($p['available'])) continue;
            $p['priority'] = (int)($p['priority'] ?? 100);
            $clean[] = $p;
        }
        usort($clean, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $clean;
    }

    /**
     * Look up one provider by id. Returns null if not registered or
     * unavailable. Use this from the checkout dispatcher.
     */
    public static function paymentProvider(string $id): ?array {
        foreach (self::paymentProviders() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    /**
     * The original synchronous checkout — used by the 'manual' provider
     * and as a fallback when no payment provider is selected.
     */
    public static function checkoutCartManual(string $sessionId, array $billing): array {
        return self::checkoutCart($sessionId, $billing);
    }

    public static function checkoutCart(string $sessionId, array $billing): array {
        $items = self::cartItems($sessionId);
        if (empty($items)) {
            return ['ok' => false, 'error' => 'Your cart is empty.'];
        }
        $email = trim($billing['email'] ?? '');
        $name  = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid email is required.'];
        }
        if ($name === ' ' || $name === '') {
            return ['ok' => false, 'error' => 'A name is required.'];
        }

        // Build line items for createOrder(). Pass variant_id so stock
        // decrements against the right row, and unit_price so the
        // already-resolved cart price (which includes variant diff) is
        // honored even if the underlying product price has since changed.
        $orderItems = [];
        foreach ($items as $i) {
            $orderItems[] = [
                'product_id' => (int)$i['product_id'],
                'qty'        => (int)$i['qty'],
                'variant_id' => !empty($i['variant_id']) ? (int)$i['variant_id'] : null,
                'unit_price' => (float)$i['unit_price'],
            ];
        }

        try {
            $orderId = self::createOrder($email, $name, $orderItems, [
                'ship_address_1' => $billing['address_1'] ?? '',
                'ship_address_2' => $billing['address_2'] ?? '',
                'ship_city'      => $billing['city']      ?? '',
                'ship_state'     => $billing['state']     ?? '',
                'ship_postcode'  => $billing['postcode']  ?? '',
                'ship_country'   => $billing['country']   ?? '',
                'notes'          => $billing['notes']     ?? '',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Clear cart on success
        self::clearCart($sessionId);

        return ['ok' => true, 'order_id' => $orderId];
    }

    /* ───────────────────── Content Builder integration ─────────────────────
     * A native product-listing block: renders a themed product grid straight
     * into the page (no iframe). Reuses the storefront's sf_product_card() so
     * cards, sale badges, variants, out-of-stock and the working add-to-cart
     * (POST /shop/cart) all match the real store; quick-add is AJAX-enhanced.
     * Grid styling uses the page's own theme tokens (--slate-color-accent, …). */

    /** Editor select options for the listing source: all products + categories. */
    public static function pickerOptions(): array {
        $out = [['v' => '', 'l' => 'All products']];
        try {
            foreach (self::categories(false) as $c) {
                if (!empty($c['slug'])) {
                    $out[] = ['v' => $c['slug'], 'l' => 'Category: ' . ($c['name'] ?? $c['slug'])];
                }
            }
        } catch (\Throwable $e) {
            // schema not ready / empty store — "All products" still works.
        }
        return $out;
    }

    /** Render the Content Builder "Product grid" block (native themed grid). */
    public static function renderContentBlock(array $props, array $block = []): string {
        if (!self::ensureStorefront()) {
            return '<div class="cb-shopgrid-empty" style="max-width:1180px;margin:2rem auto">Shop storefront unavailable.</div>';
        }
        $source = trim((string)($props['source'] ?? ''));
        $limit  = (int)($props['limit'] ?? 12);
        if ($limit < 1 || $limit > 60) $limit = 12;
        $cols   = (int)($props['columns'] ?? 3);
        if (!in_array($cols, [2, 3, 4], true)) $cols = 3;

        $viewAll = rtrim(SLATE_URL, '/') . '/shop/'
                 . ($source !== '' ? 'category/' . rawurlencode($source) : '');

        static $assetsOut = false;
        ob_start();
        if (!$assetsOut) { $assetsOut = true; echo '<style>' . self::gridCss() . '</style>' . self::gridJs(); }
        echo self::gridMarkup(self::fetchProducts($source, $limit), $cols, $viewAll);
        return ob_get_clean();
    }

    /** Load the storefront helpers (sf_product_card etc.); true if available. */
    private static function ensureStorefront(): bool {
        $f = SLATE_ROOT . '/plugins/shop/storefront/includes/layout.php';
        if (is_file($f)) require_once $f;          // guarded by function_exists inside
        return function_exists('sf_product_card');
    }

    /** Active products for a source ('' = all, else a category slug). */
    private static function fetchProducts(string $source, int $limit): array {
        $filters = ['sort' => 'newest'];
        if ($source !== '') $filters['category_slug'] = $source;
        try { return self::publishedProducts($filters, max(1, min(60, $limit)), 0); }
        catch (\Throwable $e) { return []; }
    }

    /** The product-grid markup (cards via the storefront's sf_product_card). */
    private static function gridMarkup(array $products, int $cols, ?string $viewAll): string {
        ob_start();
        // --sf-cols, shop-local. Shop DECLARED a token inside content-builder's
        // namespace and then read it back: a value that never left this file,
        // wearing another plugin's prefix. ADR-0008's amendment makes resolving
        // that part of Phase E, and the resolution is to keep it local rather
        // than migrate it to --slate-*, because a column count is a layout
        // parameter of this block, not a design token anything else can reuse.
        echo '<div class="cb-shopgrid" style="--sf-cols:' . (int)$cols . '">';
        if (!$products) {
            echo '<div class="cb-shopgrid-empty">No products to show yet.</div>';
        } else {
            echo '<div class="sf-grid">';
            foreach ($products as $p) sf_product_card($p);
            echo '</div>';
            if ($viewAll) echo '<div class="cb-shopgrid-foot"><a href="' . e($viewAll) . '">View all products &rarr;</a></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---- 2) Compact sidebar list block (thumb + name + price) ---- */

    public static function renderCompactList(array $props, array $block = []): string {
        if (!self::ensureStorefront()) return '';
        $source  = trim((string)($props['source'] ?? ''));
        $limit   = (int)($props['limit'] ?? 5);
        if ($limit < 1 || $limit > 20) $limit = 5;
        $heading = trim((string)($props['heading'] ?? ''));
        $products = self::fetchProducts($source, $limit);

        static $cssOut = false;
        ob_start();
        if (!$cssOut) { $cssOut = true; echo '<style>' . self::compactCss() . '</style>'; }
        echo '<div class="cb-prodlist">';
        if ($heading !== '') echo '<h3 class="cb-prodlist-h">' . e($heading) . '</h3>';
        if (!$products) {
            echo '<p class="cb-prodlist-empty">No products yet.</p>';
        } else {
            echo '<ul class="cb-prodlist-items">';
            foreach ($products as $p) {
                if (function_exists('sf_is_category_header') && sf_is_category_header($p)) continue;
                $url     = rtrim(SLATE_URL, '/') . '/shop/product/' . rawurlencode((string)$p['slug']);
                $img     = sf_image_url($p['image_url'] ?? null);
                $hasSale = !empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price'];
                $price   = $hasSale ? (float)$p['sale_price'] : (float)$p['price'];
                echo '<li class="cb-prodlist-item"><a href="' . e($url) . '">'
                   . '<span class="cb-prodlist-thumb"' . ($img ? ' style="background-image:url(\'' . e($img) . '\')"' : '') . '></span>'
                   . '<span class="cb-prodlist-meta">'
                   . '<span class="cb-prodlist-name">' . e(sf_clean_name($p['name'] ?? '')) . '</span>'
                   . '<span class="cb-prodlist-price">'
                   . ($hasSale ? '<s>' . e(sf_money((float)$p['price'])) . '</s> ' : '')
                   . e(sf_money($price)) . '</span>'
                   . '</span></a></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function compactCss(): string {
        return <<<CSS
.cb-prodlist{max-width:420px}
.cb-prodlist-h{font-family:var(--slate-font-heading,inherit);font-size:1.05rem;margin:0 0 .8rem;color:var(--slate-color-text,#222)}
.cb-prodlist-items{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.2rem}
.cb-prodlist-item a{display:flex;align-items:center;gap:.8rem;padding:.55rem;border-radius:var(--slate-radius-md,10px);text-decoration:none;color:inherit;transition:background .15s}
.cb-prodlist-item a:hover{background:var(--slate-color-surface-sunken,rgba(0,0,0,.04))}
.cb-prodlist-thumb{flex:none;width:52px;height:52px;border-radius:8px;background:var(--slate-color-surface-sunken,#f1ece0) center/cover no-repeat;border:1px solid rgba(0,0,0,.06)}
.cb-prodlist-meta{display:flex;flex-direction:column;gap:.15rem;min-width:0}
.cb-prodlist-name{font-weight:600;color:var(--slate-color-text,#222);font-size:.92rem;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cb-prodlist-price{font-size:.86rem;color:var(--slate-color-accent-strong,#cf4520);font-weight:600}
.cb-prodlist-price s{color:var(--slate-color-text-muted,#999);font-weight:400;margin-right:.25rem}
.cb-prodlist-empty{color:var(--slate-color-text-muted,#777);font-size:.9rem}
CSS;
    }

    /* ---- 3) External embeddable page (iframe snippet for outside sites) ---- */

    /** Standalone HTML doc with the product grid, for embedding on any site via
     *  <iframe>. Cards open the real shop in a new tab (base target=_blank), so
     *  product pages / cart work in a same-site context. Posts height for resize. */
    public static function renderEmbedPage(string $source, int $limit, int $cols): string {
        if (!self::ensureStorefront()) {
            http_response_code(503);
            return '<!doctype html><meta charset="utf-8"><p>Shop unavailable.</p>';
        }
        $cols  = in_array($cols, [2, 3, 4], true) ? $cols : 3;
        $limit = ($limit < 1 || $limit > 60) ? 12 : $limit;
        $grid  = self::gridMarkup(self::fetchProducts($source, $limit), $cols, null);
        $css   = self::gridCss();

        return '<!doctype html><html><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<base target="_blank">'
             . '<style>body{margin:0;background:transparent;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
             . '.cb-shopgrid{margin:0}' . $css . '</style></head><body>'
             . $grid
             . '<script>(function(){function h(){var b=document.body,d=document.documentElement;'
             . 'var ht=Math.max(b.scrollHeight,d.scrollHeight,b.offsetHeight,d.offsetHeight);'
             . 'try{parent.postMessage({type:"cb-shop-embed-height",height:ht},"*");}catch(e){}}'
             . 'window.addEventListener("load",h);window.addEventListener("resize",h);'
             . 'if(window.ResizeObserver){try{new ResizeObserver(h).observe(document.body);}catch(e){}}'
             . 'setTimeout(h,300);})();</script></body></html>';
    }

    /** Scoped grid CSS — styles the storefront's .sf-* card classes using the
     *  host page's theme tokens so the listing fits any page design. */
    private static function gridCss(): string {
        return <<<CSS
.cb-shopgrid{max-width:1180px;margin:2rem auto}
.cb-shopgrid .sf-grid{display:grid;grid-template-columns:repeat(var(--sf-cols,3),1fr);gap:1.25rem}
.cb-shopgrid .sf-product-card-wrap{display:flex;flex-direction:column;background:var(--slate-color-surface,#fff);border:1px solid rgba(0,0,0,.08);border-radius:calc(var(--slate-radius-md,12px) + 2px);overflow:hidden;transition:transform .2s,box-shadow .2s}
.cb-shopgrid .sf-product-card-wrap:hover{transform:translateY(-3px);box-shadow:0 14px 32px -16px rgba(0,0,0,.4)}
.cb-shopgrid .sf-product-card{display:block;color:inherit;text-decoration:none;flex:1}
.cb-shopgrid .sf-product-img{position:relative;aspect-ratio:1/1;background:var(--slate-color-surface-sunken,#f1ece0);display:flex;align-items:center;justify-content:center;overflow:hidden}
.cb-shopgrid .sf-product-img img{width:100%;height:100%;object-fit:cover;display:block}
.cb-shopgrid .sf-placeholder svg{width:34px;height:34px;color:var(--slate-color-text-muted,#999);opacity:.55}
.cb-shopgrid .sf-featured-badge,.cb-shopgrid .sf-sale-badge{position:absolute;top:10px;font-size:.64rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:.28rem .55rem;border-radius:100px;z-index:2}
.cb-shopgrid .sf-featured-badge{left:10px;background:var(--slate-color-text,#222);color:#fff}
.cb-shopgrid .sf-sale-badge{right:10px;background:var(--slate-color-accent,#cf4520);color:var(--slate-color-on-accent,#fff)}
.cb-shopgrid .sf-stock-out{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.72);font-weight:700;color:var(--slate-color-text,#222);text-transform:uppercase;letter-spacing:.05em;font-size:.8rem}
.cb-shopgrid .sf-product-info{padding:.9rem 1rem}
.cb-shopgrid .sf-product-name{font-family:var(--slate-font-heading,inherit);font-weight:600;font-size:1rem;color:var(--slate-color-text,#222);line-height:1.25;margin-bottom:.25rem}
.cb-shopgrid .sf-product-cat{font-size:.82rem;color:var(--slate-color-text-muted,#777);margin-bottom:.5rem}
.cb-shopgrid .sf-product-price{font-weight:700;color:var(--slate-color-text,#222);font-size:1rem}
.cb-shopgrid .sf-product-price .sf-sale{color:var(--slate-color-accent-strong,#cf4520);margin-right:.4rem}
.cb-shopgrid .sf-product-price .sf-orig{color:var(--slate-color-text-muted,#999);text-decoration:line-through;font-weight:500;font-size:.88rem}
.cb-shopgrid .sf-card-add,.cb-shopgrid .sf-card-add-link{display:flex;align-items:center;justify-content:center;gap:.45rem;width:100%;margin:0;padding:.7rem 1rem;border:0;border-top:1px solid rgba(0,0,0,.06);background:var(--slate-color-accent,#cf4520);color:var(--slate-color-on-accent,#fff);font:inherit;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
.cb-shopgrid .sf-card-add:hover{background:var(--slate-color-accent-strong,#a8390f)}
.cb-shopgrid .sf-card-add-form{margin:0}
.cb-shopgrid .sf-card-add-disabled{background:var(--slate-color-surface-sunken,#eee);color:var(--slate-color-text-muted,#888);cursor:not-allowed}
.cb-shopgrid .sf-category-header{grid-column:1 / -1;padding:.5rem 0 .25rem}
.cb-shopgrid .sf-category-header-title{font-family:var(--slate-font-heading,inherit);font-size:1.3rem;margin:0 0 .25rem;color:var(--slate-color-text,#222)}
.cb-shopgrid .sf-category-header-blurb{color:var(--slate-color-text-muted,#777);margin:0}
.cb-shopgrid-foot{text-align:center;margin-top:1.6rem}
.cb-shopgrid-foot a{color:var(--slate-color-accent-strong,#cf4520);font-weight:600;text-decoration:none}
.cb-shopgrid-empty{text-align:center;color:var(--slate-color-text-muted,#777);padding:2rem;border:1px dashed rgba(0,0,0,.15);border-radius:12px;max-width:1180px;margin:2rem auto}
@media(max-width:900px){.cb-shopgrid .sf-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.cb-shopgrid .sf-grid{grid-template-columns:1fr}}
CSS;
    }

    /** Quick-add AJAX: POST the card's add form, flash "Added", no reload.
     *  Falls back to a normal submit (lands on /shop/cart) if anything fails. */
    private static function gridJs(): string {
        return <<<'JS'
<script>(function(){
  if (window.__cbShopGrid) return; window.__cbShopGrid = 1;
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.classList || !f.classList.contains('sf-card-add-form')) return;
    if (!f.closest('.cb-shopgrid')) return;
    e.preventDefault();
    var btn = f.querySelector('.sf-card-add');
    var span = btn && btn.querySelector('span');
    var orig = span ? span.textContent : '';
    if (btn) btn.disabled = true;
    fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) {
          if (span) span.textContent = 'Added ✓';
          setTimeout(function () { if (span) span.textContent = orig || 'Add to cart'; if (btn) btn.disabled = false; }, 1600);
        } else { f.submit(); }
      })
      .catch(function () { f.submit(); });
  });
})();</script>
JS;
    }
}

