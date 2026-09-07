<?php
/**
 * Shop storefront — cart.
 * URL: /shop/cart
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

$sid = sf_session_id();
$flash = null;

// Detect AJAX (Accept: application/json) — used by the listing
// tiles' quick add-to-cart. AJAX requests get a JSON response
// instead of redirecting to the cart page.
$wantsJson = (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

function sf_cart_json_response(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sf_csrf_verify()) {
        if ($wantsJson) {
            http_response_code(403);
            sf_cart_json_response(['ok' => false, 'error' => 'Security check failed.']);
        }
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'add') {
            // Quick-add from the listing tile. We require product_id;
            // qty defaults to 1. Variants aren't supported through this
            // path — products with variants force the customer to the
            // detail page (the listing card renders "Choose options"
            // for them, not "Add to cart").
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = max(1, (int)($_POST['qty'] ?? 1));
            if ($pid <= 0) {
                if ($wantsJson) {
                    http_response_code(400);
                    sf_cart_json_response(['ok' => false, 'error' => 'Invalid product.']);
                }
                $flash = ['type' => 'error', 'msg' => 'Invalid product.'];
            } else {
                $res = ShopAPI::addToCart($sid, $pid, $qty, null);
                if (!empty($res['ok'])) {
                    if ($wantsJson) {
                        sf_cart_json_response([
                            'ok'         => true,
                            'cart_count' => sf_cart_count(),
                        ]);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Added to cart.'];
                } else {
                    if ($wantsJson) {
                        http_response_code(400);
                        sf_cart_json_response(['ok' => false, 'error' => $res['error'] ?? 'Add failed.']);
                    }
                    $flash = ['type' => 'error', 'msg' => $res['error'] ?? 'Add failed.'];
                }
            }
        }
        elseif ($action === 'update_qty') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $qty    = (int)($_POST['qty'] ?? 0);
            ShopAPI::updateCartQty($sid, $itemId, $qty);
            $flash = ['type' => 'success', 'msg' => 'Cart updated.'];
        } elseif ($action === 'remove') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            ShopAPI::removeCartItem($sid, $itemId);
            $flash = ['type' => 'success', 'msg' => 'Removed from cart.'];
        } elseif ($action === 'clear') {
            ShopAPI::clearCart($sid);
            $flash = ['type' => 'success', 'msg' => 'Cart cleared.'];
        }
    }
}

$cart = ShopAPI::cartTotals($sid);

sf_header('Cart');
?>

<h1 class="sf-h1">Your cart</h1>
<p class="sf-sub"><?= $cart['item_count'] ?> item<?= $cart['item_count'] === 1 ? '' : 's' ?></p>

<?php if ($flash): ?>
    <div class="sf-flash sf-flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (empty($cart['items'])): ?>
    <div class="sf-card" style="text-align:center; padding:60px 20px;">
        <div class="sf-placeholder" style="width:64px;height:64px;border-radius:18px;background:var(--bg-2);display:grid;place-items:center;margin:0 auto 16px;color:var(--subtle);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>
            </svg>
        </div>
        <h2 class="sf-h2">Your cart is empty</h2>
        <p class="sf-sub">Start adding products to see them here.</p>
        <a href="<?= e(SLATE_URL) ?>/shop/" class="sf-btn sf-btn-primary">Browse products</a>
    </div>
<?php else: ?>
    <div class="sf-checkout-layout">

        <!-- Items -->
        <div class="sf-card">
            <table class="sf-cart-table">
                <thead>
                    <tr><th></th><th>Item</th><th>Price</th><th>Qty</th><th style="text-align:right">Total</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($cart['items'] as $i):
                    $imgUrl = sf_image_url($i['image_url'] ?? null);
                    $lineTotal = (float)$i['unit_price'] * (int)$i['qty'];
                ?>
                    <tr>
                        <td style="width:72px;">
                            <?php if ($imgUrl): ?>
                                <img class="sf-cart-row-img" src="<?= e($imgUrl) ?>" alt="">
                            <?php else: ?>
                                <div class="sf-cart-row-img" style="display:grid;place-items:center;color:var(--subtle);">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                                         stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;">
                                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                        <path d="M3.27 6.96L12 12l8.73-5.04M12 22V12"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600;font-size:13.5px;line-height:1.35;">
                                <a href="<?= e(SLATE_URL) ?>/shop/product/<?= e($i['product_slug']) ?>">
                                    <?= e($i['product_name']) ?>
                                </a>
                            </div>
                            <?php if (!empty($i['variant_value'])): ?>
                                <div class="sf-product-cat" style="margin-top:2px;">
                                    <?= e($i['variant_attribute']) ?>: <?= e($i['variant_value']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-variant-numeric:tabular-nums;"><?= e(sf_money((float)$i['unit_price'])) ?></td>
                        <td>
                            <form method="post" style="display:flex; gap:6px; align-items:center;">
                                <?= sf_csrf_field() ?>
                                <input type="hidden" name="_action" value="update_qty">
                                <input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>">
                                <input type="number" class="sf-input sf-cart-qty"
                                       name="qty" value="<?= (int)$i['qty'] ?>" min="0" max="999"
                                       aria-label="Quantity for <?= e($i['product_name']) ?>">
                                <button type="submit" class="sf-btn" style="padding:9px 12px;font-size:14px;line-height:1;"
                                        title="Update quantity" aria-label="Update quantity for <?= e($i['product_name']) ?>">↻</button>
                            </form>
                        </td>
                        <td style="text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">
                            <?= e(sf_money($lineTotal)) ?>
                        </td>
                        <td style="width:48px; text-align:right;">
                            <form method="post" style="display:inline">
                                <?= sf_csrf_field() ?>
                                <input type="hidden" name="_action" value="remove">
                                <input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>">
                                <button type="submit" class="sf-btn" style="padding:9px 12px;font-size:16px;line-height:1;"
                                        title="Remove item" aria-label="Remove <?= e($i['product_name']) ?> from cart">×</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:18px; display:flex; gap:8px; flex-wrap:wrap;">
                <form method="post" style="display:inline">
                    <?= sf_csrf_field() ?>
                    <input type="hidden" name="_action" value="clear">
                    <button type="submit" class="sf-btn"
                            onclick="return confirm('Clear the entire cart?');">Clear cart</button>
                </form>
                <a href="<?= e(SLATE_URL) ?>/shop/" class="sf-btn">Continue shopping</a>
            </div>
        </div>

        <!-- Order summary -->
        <div class="sf-summary">
            <div class="sf-card">
                <h2 class="sf-h2">Order summary</h2>
                <div class="sf-summary-line">
                    <span>Subtotal</span>
                    <span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['subtotal'])) ?></span>
                </div>
                <?php if ($cart['shipping'] > 0): ?>
                <div class="sf-summary-line">
                    <span>Shipping</span>
                    <span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['shipping'])) ?></span>
                </div>
                <?php
                // Shipping plugins can append a small breakdown line
                // here (e.g. "Ships in: 1 Medium box"). The hook
                // receives the cart array and returns HTML to inject
                // directly below the shipping price.
                if (class_exists('Hook')) {
                    $breakdown = Hook::applyFilters('shop_cart_shipping_breakdown', '', $cart);
                    if (is_string($breakdown) && $breakdown !== '') {
                        echo $breakdown;
                    }
                }
                ?>
                <?php endif; ?>
                <?php if ($cart['tax'] > 0): ?>
                <div class="sf-summary-line">
                    <span>Tax (<?= e((string)$cart['tax_rate']) ?>%)</span>
                    <span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['tax'])) ?></span>
                </div>
                <?php endif; ?>
                <div class="sf-summary-line total">
                    <span>Total</span>
                    <span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['total'])) ?></span>
                </div>
                <a href="<?= e(SLATE_URL) ?>/shop/checkout"
                   class="sf-btn sf-btn-primary sf-btn-block"
                   style="margin-top:18px;">
                    Proceed to checkout →
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php sf_footer(); ?>
