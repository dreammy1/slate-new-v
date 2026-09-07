<?php
/**
 * Shop storefront — order confirmation.
 *
 * URL: /shop/order?key=<view_token>
 *
 * Lookup is by view_token, a 32-hex-char (128-bit) capability key
 * generated with random_bytes() when the order was created. The
 * customer received this in the redirect from checkout; anyone with
 * the URL can view the order. That's the same trust model as a
 * guest-checkout confirmation page; we deliberately do NOT use the
 * numeric id or the sequential order_number here — those would let
 * an attacker enumerate every order on the store.
 *
 * For backwards compatibility (orders placed before the v1.2.6
 * upgrade may still have URLs containing order_number in transit —
 * customer's email inbox, Stripe receipt, etc.), we fall back to
 * order_number lookup ONLY for orders whose view_token is NULL.
 * After backfill these are gone, and new orders only resolve by
 * view_token.
 */

$root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
if (!file_exists($root . '/config.php')) {
    $dir = __DIR__;
    while ($dir !== '/' && !file_exists($dir . '/config.php')) $dir = dirname($dir);
    $root = $dir;
}
require $root . '/config.php';

if (!class_exists('ShopAPI') || !PluginLoader::isActive('shop')) {
    http_response_code(503);
    echo '<h1>Shop unavailable</h1>'; exit;
}

require __DIR__ . '/includes/layout.php';

$key = trim((string)($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(404);
    sf_header('Order not found');
    echo '<h1 class="sf-h1">Order not found</h1>';
    echo '<p class="sf-sub">No order key was provided.</p>';
    echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Back to shop</a>';
    sf_footer(); exit;
}

// Lookup by view_token (the capability key). Reject keys that aren't
// the expected 32-hex shape — a real token only matches /^[a-f0-9]{32}$/i,
// and rejecting other shapes also blocks anyone trying the legacy
// "ORD-00001" enumeration that used to work.
$order = null;
if (preg_match('/^[a-f0-9]{32}$/i', $key)) {
    $order = Database::row(
        "SELECT * FROM shop_orders
          WHERE view_token = ? AND tenant_id = ?",
        [strtolower($key), current_tenant_id()]
    );
}
// Legacy compatibility: orders placed before v1.2.6 — including any
// already-sent emails / receipts pointing at /shop/order?key=ORD-00001 —
// may not have a view_token yet (backfill is best-effort). Allow lookup
// by order_number, but ONLY when view_token is still NULL on that row,
// so the legacy path closes for itself as backfill completes. New orders
// always have a view_token and are never reachable through this branch.
if (!$order && $key !== '') {
    $order = Database::row(
        "SELECT * FROM shop_orders
          WHERE order_number = ?
            AND tenant_id = ?
            AND (view_token IS NULL OR view_token = '')",
        [$key, current_tenant_id()]
    );
}

if (!$order) {
    http_response_code(404);
    sf_header('Order not found');
    echo '<h1 class="sf-h1">Order not found</h1>';
    echo '<p class="sf-sub">We couldn\'t find an order with that key. ';
    echo 'If you just placed an order and ended up here, please check the link in your confirmation email.</p>';
    echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Back to shop</a>';
    sf_footer(); exit;
}

// Line items
$items = Database::rows(
    "SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id ASC",
    [(int)$order['id']]
);

$statusLabels = [
    'pending'    => ['Pending payment', 'sf-flash-info'],
    'processing' => ['Processing',       'sf-flash-info'],
    'on-hold'    => ['On hold',          'sf-flash-info'],
    'completed'  => ['Completed',        'sf-flash-success'],
    'cancelled'  => ['Cancelled',        'sf-flash-error'],
    'refunded'   => ['Refunded',         'sf-flash-error'],
];
[$statusLabel, $statusClass] = $statusLabels[$order['status']] ?? ['Pending', 'sf-flash-info'];

sf_header('Order ' . $order['order_number']);
?>

<div style="text-align:center; padding: 24px 0 32px;">
    <div style="font-size:3.5rem; margin-bottom:8px;">✓</div>
    <h1 class="sf-h1" style="margin-bottom:8px;">Thank you for your order!</h1>
    <p class="sf-sub" style="margin:0;">
        Your order <strong><?= e($order['order_number']) ?></strong> has been received.
    </p>
</div>

<div class="sf-flash <?= e($statusClass) ?>" style="text-align:center; margin-bottom:24px;">
    Status: <strong><?= e($statusLabel) ?></strong>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">

    <!-- Items + totals -->
    <div class="sf-card">
        <h2 class="sf-h2">Order details</h2>

        <table class="sf-cart-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align:center; width: 80px;">Qty</th>
                    <th style="text-align:right; width: 120px;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= e($i['product_name']) ?></div>
                            <?php if (!empty($i['product_sku'])): ?>
                                <div class="sf-product-cat">SKU: <?= e($i['product_sku']) ?></div>
                            <?php endif; ?>
                            <div class="sf-product-cat">
                                <?= e(sf_money((float)$i['unit_price'])) ?> each
                            </div>
                        </td>
                        <td style="text-align:center;"><?= (int)$i['quantity'] ?></td>
                        <td style="text-align:right; font-weight:600;">
                            <?= e(sf_money((float)$i['line_total'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border);">
            <div style="display:flex; justify-content:space-between; padding:4px 0;">
                <span>Subtotal</span>
                <span><?= e(sf_money((float)$order['subtotal'])) ?></span>
            </div>
            <?php if ((float)$order['discount_total'] > 0): ?>
                <div style="display:flex; justify-content:space-between; padding:4px 0; color:var(--accent);">
                    <span>Discount<?= !empty($order['coupon_code']) ? ' (' . e($order['coupon_code']) . ')' : '' ?></span>
                    <span>−<?= e(sf_money((float)$order['discount_total'])) ?></span>
                </div>
            <?php endif; ?>
            <?php if ((float)$order['shipping_total'] > 0): ?>
                <div style="display:flex; justify-content:space-between; padding:4px 0;">
                    <span>Shipping</span>
                    <span><?= e(sf_money((float)$order['shipping_total'])) ?></span>
                </div>
            <?php endif; ?>
            <?php if ((float)$order['tax_total'] > 0): ?>
                <div style="display:flex; justify-content:space-between; padding:4px 0;">
                    <span>Tax</span>
                    <span><?= e(sf_money((float)$order['tax_total'])) ?></span>
                </div>
            <?php endif; ?>
            <div style="display:flex; justify-content:space-between; padding:14px 0 4px;
                        border-top:1px solid var(--border); margin-top:8px;
                        font-weight:700; font-size:1.1rem;">
                <span>Total</span>
                <span><?= e(sf_money((float)$order['total'])) ?></span>
            </div>
        </div>

        <?php if (!empty($order['notes'])): ?>
            <div style="margin-top:20px; padding:14px; background:var(--surface-2);
                        border-radius:var(--radius-sm);">
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px;
                            color:var(--text-muted); margin-bottom:4px;">Order notes</div>
                <div><?= nl2br(e($order['notes'])) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Customer + shipping sidebar -->
    <div>
        <div class="sf-card">
            <h2 class="sf-h2">Customer</h2>
            <div style="padding:4px 0;">
                <div style="font-weight:600;"><?= e($order['customer_name']) ?></div>
                <div class="sf-product-cat" style="margin-top:2px;"><?= e($order['customer_email']) ?></div>
            </div>
        </div>

        <?php
        $hasAddr = !empty($order['ship_address_1']) || !empty($order['ship_city'])
                || !empty($order['ship_country']);
        ?>
        <?php if ($hasAddr): ?>
        <div class="sf-card">
            <h2 class="sf-h2">Shipping address</h2>
            <div style="line-height:1.6;">
                <?php if (!empty($order['ship_address_1'])): ?>
                    <?= e($order['ship_address_1']) ?><br>
                <?php endif; ?>
                <?php if (!empty($order['ship_address_2'])): ?>
                    <?= e($order['ship_address_2']) ?><br>
                <?php endif; ?>
                <?php
                $cityLine = trim(
                    ($order['ship_city'] ?? '')
                    . (!empty($order['ship_state']) ? ', ' . $order['ship_state'] : '')
                    . (!empty($order['ship_postcode']) ? ' ' . $order['ship_postcode'] : '')
                );
                ?>
                <?php if ($cityLine !== ''): ?>
                    <?= e($cityLine) ?><br>
                <?php endif; ?>
                <?php if (!empty($order['ship_country'])): ?>
                    <?= e($order['ship_country']) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="sf-card">
            <h2 class="sf-h2">Order date</h2>
            <div><?= e($order['created_at']) ?></div>
        </div>
    </div>
</div>

<div style="text-align:center; margin-top:32px;">
    <a href="<?= e(SLATE_URL) ?>/shop/" class="sf-btn">Continue shopping</a>
</div>

<style>
@media (max-width: 768px) {
    div[style*="grid-template-columns: 2fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php sf_footer(); ?>
