<?php
/**
 * ShippingAPI — public methods used by the plugin's own admin
 * pages, the storefront cart, and the calculation hook.
 *
 * The interesting bit is packCart() / priceForCart() — the greedy
 * bin-packing logic that decides how many boxes of which size are
 * needed to ship a cart, and what to charge for them.
 *
 * Algorithm:
 *   1. Group cart items by their box assignment (each product has a
 *      shipping_box_slug; unassigned items use the smallest active
 *      box as fallback).
 *   2. For each group, multiply quantity to get total items needing
 *      that box size.
 *   3. Run a "shrink-fit pass": items assigned to small boxes that
 *      fit alongside items assigned to a larger box should bubble
 *      up into the larger box for free, not require their own box.
 *   4. Number of boxes = ceil(items_for_size / box.capacity) for
 *      each size that still has items left.
 *   5. Total = sum of (boxes_at_size × box.price).
 *
 * Notes on the algorithm:
 *  - This is NOT optimal bin-packing (which is NP-hard). It's a
 *    "good enough" greedy heuristic that's predictable for shop
 *    admins: a Large-box product always ships in at least one
 *    Large box.
 *  - The "shrink-fit" pass only moves smaller items UP into a larger
 *    box already required. It never downgrades a larger item into a
 *    smaller box, because the admin's box-per-product assignment is
 *    the floor on what's needed.
 *  - Capacity is in "items", not weight or volume. This is a
 *    deliberate simplification — most stores have items that don't
 *    vary wildly in size within a box class. If you have weird
 *    asymmetric inventory (a few huge items, mostly tiny), the
 *    capacity-as-count model breaks down; you'd want a true 3D bin-
 *    packer at that point. That's out of scope here.
 */

class ShippingAPI {

    /**
     * Return all active boxes ordered by sort_order ASC (smallest /
     * cheapest first by convention).
     */
    public static function activeBoxes(): array {
        return Database::rows(
            "SELECT * FROM shippingflatrate_boxes
              WHERE tenant_id = ? AND is_active = 1
              ORDER BY sort_order ASC, price ASC, id ASC",
            [current_tenant_id()]
        );
    }

    /** All boxes regardless of active state (for the admin page). */
    public static function allBoxes(): array {
        return Database::rows(
            "SELECT * FROM shippingflatrate_boxes
              WHERE tenant_id = ?
              ORDER BY sort_order ASC, price ASC, id ASC",
            [current_tenant_id()]
        );
    }

    public static function box(int $id): ?array {
        $r = Database::row(
            "SELECT * FROM shippingflatrate_boxes WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]);
        return $r ?: null;
    }

    public static function boxBySlug(string $slug): ?array {
        $r = Database::row(
            "SELECT * FROM shippingflatrate_boxes WHERE slug = ? AND tenant_id = ?",
            [$slug, current_tenant_id()]);
        return $r ?: null;
    }

    /**
     * Calculate the shipping price for a cart's items.
     *
     * Returns null when no boxes are configured, signalling the caller
     * to fall through to whatever default (shop's flat rate).
     * Returns a non-negative float (rounded to 2dp) when boxes are
     * available.
     *
     * The $items array is whatever the shop_calculate_shipping filter
     * passes in. Each item must have:
     *   - product_id : int
     *   - quantity OR qty : int (createOrder uses 'quantity',
     *                            cartTotals uses 'qty')
     */
    public static function priceForCart(array $items): ?float {
        $packed = self::packCart($items);
        if ($packed === null) return null;

        $total = 0.0;
        foreach ($packed['boxes'] as $row) {
            $total += $row['count'] * (float)$row['box']['price'];
        }
        return round($total, 2);
    }

    /**
     * Pack a cart into boxes. Returns:
     *
     *   [
     *     'boxes' => [
     *        ['box' => <box row>, 'count' => 2],
     *        ['box' => <box row>, 'count' => 1],
     *     ],
     *     'totals' => [
     *        'box_count'  => 3,
     *        'item_count' => 14,
     *        'price'      => 51.30,
     *     ],
     *   ]
     *
     * Or null when there are no active boxes (caller should fall
     * back to shop core's default).
     */
    public static function packCart(array $items): ?array {
        $boxes = self::activeBoxes();
        if (empty($boxes)) return null;

        // Index boxes by slug for fast lookup; sort_order ascending.
        $bySlug = [];
        foreach ($boxes as $b) $bySlug[$b['slug']] = $b;

        // Default fallback box: first active by sort_order (smallest /
        // cheapest by convention).
        $defaultBox = $boxes[0];

        // Group items by their assigned box. Items without an
        // assignment, or whose assigned box has been deactivated, get
        // the default box.
        //
        // $buckets is keyed by box slug; each value is the total qty
        // of items going into that box class.
        $buckets = [];
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? $it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $boxSlug = self::resolveProductBoxSlug($pid);
            if (!$boxSlug || !isset($bySlug[$boxSlug])) {
                $boxSlug = $defaultBox['slug'];
            }
            $buckets[$boxSlug] = ($buckets[$boxSlug] ?? 0) + $qty;
        }

        if (empty($buckets)) {
            // No items at all (cart is empty). Return zero so the
            // caller charges nothing.
            return [
                'boxes'  => [],
                'totals' => ['box_count' => 0, 'item_count' => 0, 'price' => 0.0],
            ];
        }

        // Compute boxes-needed per bucket BEFORE the shrink-fit pass.
        // We need to know if the larger-box buckets will have spare
        // capacity that smaller-box items could ride along in.
        //
        // Walk buckets from LARGEST capacity to SMALLEST. At each
        // larger bucket, after counting how many boxes that bucket
        // needs, fill the LAST box's spare capacity with items from
        // smaller buckets.
        $sortedBoxes = $boxes;
        usort($sortedBoxes, function ($a, $b) {
            // Sort by capacity DESC, then sort_order DESC
            $cap = (int)$b['capacity'] - (int)$a['capacity'];
            if ($cap !== 0) return $cap;
            return (int)$b['sort_order'] - (int)$a['sort_order'];
        });

        $result = [];   // [['box' => row, 'count' => N], ...]
        foreach ($sortedBoxes as $box) {
            $slug = $box['slug'];
            $qty  = $buckets[$slug] ?? 0;
            if ($qty <= 0) continue;

            $cap   = max(1, (int)$box['capacity']);
            $boxes_needed = (int)ceil($qty / $cap);
            // Spare capacity in the LAST box of this size.
            $spare = ($boxes_needed * $cap) - $qty;

            // Shrink-fit: pull items from any smaller-capacity bucket
            // into our spare. Walk all OTHER buckets that we haven't
            // processed yet (those with smaller capacity than $box).
            if ($spare > 0) {
                foreach ($sortedBoxes as $candidate) {
                    if ($candidate['slug'] === $slug) continue;
                    if ((int)$candidate['capacity'] >= (int)$box['capacity']) continue;
                    $cslug = $candidate['slug'];
                    $cqty  = $buckets[$cslug] ?? 0;
                    if ($cqty <= 0) continue;

                    // Move min(spare, cqty) items from candidate into
                    // our spare.
                    $move = min($spare, $cqty);
                    $buckets[$cslug] -= $move;
                    $spare           -= $move;
                    if ($spare <= 0) break;
                }
            }

            $result[] = ['box' => $box, 'count' => $boxes_needed];
            unset($buckets[$slug]);
        }

        // Anything left in $buckets (shouldn't normally happen — every
        // bucket's slug exists in $sortedBoxes — but defensive: any
        // leftover gets its own boxes via direct calculation).
        foreach ($buckets as $slug => $qty) {
            if ($qty <= 0) continue;
            if (!isset($bySlug[$slug])) continue;
            $cap = max(1, (int)$bySlug[$slug]['capacity']);
            $boxes_needed = (int)ceil($qty / $cap);
            $result[] = ['box' => $bySlug[$slug], 'count' => $boxes_needed];
        }

        // Totals
        $boxCount  = 0;
        $price     = 0.0;
        $itemCount = 0;
        foreach ($result as $row) {
            $boxCount += $row['count'];
            $price    += $row['count'] * (float)$row['box']['price'];
        }
        foreach ($items as $it) {
            $itemCount += (int)($it['quantity'] ?? $it['qty'] ?? 0);
        }

        return [
            'boxes'  => $result,
            'totals' => [
                'box_count'  => $boxCount,
                'item_count' => $itemCount,
                'price'      => round($price, 2),
            ],
        ];
    }

    /**
     * Look up the box assignment for a product. Cached in a static
     * per-request to avoid re-querying for the same product when
     * carts have multiple line items of the same product (variant
     * splits, e.g.).
     */
    private static function resolveProductBoxSlug(int $productId): ?string {
        static $cache = [];
        if (array_key_exists($productId, $cache)) return $cache[$productId];
        try {
            $slug = Database::value(
                "SELECT shipping_box_slug FROM shop_products WHERE id = ? AND tenant_id = ?",
                [$productId, current_tenant_id()]
            );
        } catch (\Throwable $e) {
            $slug = null;
        }
        $slug = is_string($slug) && $slug !== '' ? $slug : null;
        $cache[$productId] = $slug;
        return $slug;
    }
}
