<?php
/**
 * Flat-rate-shipping plugin.
 *
 * Registers a 'shop_shipping_rate' filter that computes a shipping rate
 * from the cart's total weight against a configurable tier ladder.
 *
 * Flow:
 *   1. ShopAPI::calculateShipping() applies free-shipping threshold first.
 *      If that fires, we never get called.
 *   2. Otherwise, ShopAPI fires the 'shop_shipping_rate' filter.
 *   3. We sum item weights, find the matching tier (min <= w < max), return
 *      its rate. If no tier matches we return null, and ShopAPI falls
 *      through to its legacy hardcoded flat rate.
 *
 * "Items" passed in are line items (product_id, qty, unit_price). We
 * look up each product's weight from the shop_products table — they
 * aren't on the line item itself because weight isn't part of the
 * order schema (orders denormalize price/name/sku but not weight).
 *
 * Why not store weight on the line item? Weight is a shipping concept,
 * and the order is a financial record. If we ever change weight after
 * an order ships, the order's snapshot shouldn't change. But we're
 * computing shipping AT cart/checkout time, when the current weight
 * IS the right one. So we look up live.
 */

class FlatRateShipping extends Plugin {

    public function boot(): void {
        // Register our rate calculator on the Shop shipping filter.
        // ShopAPI calls applyFilters('shop_shipping_rate', null, $context)
        // and we return either a float or null (= "I have no opinion;
        // let the next plugin or the legacy default decide").
        Hook::addFilter('shop_shipping_rate', [$this, 'calculateRate'], 10);

        // Sidebar nav: under "Shop"-ish, but the plugin doesn't strictly
        // depend on Shop being installed, so we just register a top-level
        // nav item that any shop.* permission can see.
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
    }

    public function addAdminNav(array $items): array {
        // Only show the nav item to users who can manage shipping.
        if (!Auth::can('shipping.manage') && !Auth::isSuperAdmin()) {
            return $items;
        }
        $items[] = [
            'slug'   => 'shipping-flat-rate',
            'label'  => __('shipping_rates', 'Shipping rates'),
            'href'   => $this->url('admin/index.php'),
            'icon'   => 'truck',
            'order'  => 520,
            'group' => 'shop',  // displays under Shop if Shop nav exists
        ];
        return $items;
    }

    /**
     * The filter callback. Returns a float (rate) or null (no opinion).
     *
     * @param float|null $current   The current rate, threaded through the filter chain.
     *                              Non-null = a previous plugin already picked. We
     *                              respect that — don't second-guess. (First-write-
     *                              wins; admins control priority by reordering
     *                              plugins.)
     * @param array      $context   ['subtotal' => float, 'items' => array, 'billing' => array]
     */
    public function calculateRate($current, ?array $context = null) {
        // Honor whatever the previous plugin set.
        if ($current !== null) return $current;

        // Sum total cart weight. We look up product weight live from the DB.
        $items = is_array($context) && isset($context['items']) ? $context['items'] : [];
        if (empty($items)) return null; // empty cart? let the fallback handle it

        $totalWeight = 0.0;
        foreach ($items as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['qty'] ?? $line['quantity'] ?? 1);
            if ($pid <= 0 || $qty <= 0) continue;

            $w = Database::value(
                "SELECT weight FROM shop_products WHERE id = ?",
                [$pid]
            );
            $totalWeight += (float)$w * $qty;
        }

        // Round to 3 decimals to match the column scale and avoid
        // floating-point boundary surprises (a 7.9999oz cart should hit
        // the 0-8 tier, not the 8-32 one).
        $totalWeight = round($totalWeight, 3);

        // Look up the first active tier whose [min, max) bracket contains
        // totalWeight. NULL max means "no upper bound" — the overflow
        // tier. We sort by display_order then min_weight so admins can
        // override the natural ordering if they need a special case.
        $tid  = current_tenant_id();
        $tier = Database::row(
            "SELECT id, label, rate, max_weight FROM flatrateshipping_tiers
              WHERE tenant_id = ?
                AND active = 1
                AND min_weight <= ?
                AND (max_weight IS NULL OR max_weight > ?)
              ORDER BY display_order ASC, min_weight ASC
              LIMIT 1",
            [$tid, $totalWeight, $totalWeight]
        );

        // No matching tier — return null so ShopAPI falls through to the
        // legacy hardcoded rate. Don't return 0, that would mean "free"
        // and surprise the customer with an unexpectedly cheap shipping
        // when the admin probably just forgot to configure heavy-weight
        // tiers.
        if (!$tier) return null;

        return (float)$tier['rate'];
    }
}
