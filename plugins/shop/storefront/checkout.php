<?php
/**
 * Shop storefront — checkout.
 * URL: /shop/checkout
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
$cart = ShopAPI::cartTotals($sid);

if (empty($cart['items'])) {
    sf_header('Checkout');
    echo '<h1 class="sf-h1">Your cart is empty</h1>';
    echo '<p class="sf-sub">Add products before checking out.</p>';
    echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Browse products</a>';
    sf_footer(); exit;
}

$flash = null;
$form = [
    'first_name' => '', 'last_name' => '', 'email' => '',
    'address_1'  => '', 'address_2' => '',
    'city'       => '', 'state'     => '', 'postcode' => '', 'country' => '',
    'notes'      => '',
];

// Honour ?error=... query param from off-site payment-flow returns
// (Stripe Cancel button → /shop/checkout?error=cancelled, etc).
if (isset($_GET['error']) && $flash === null) {
    $errorMap = [
        'cancelled'              => 'Payment cancelled. Your cart is still here.',
        'payment_not_completed'  => 'Payment was not completed. Please try again.',
        'order_failed'           => 'Payment succeeded but we couldn’t record the order. Please contact us.',
        'missing'                => 'Payment session expired. Please try again.',
    ];
    $code = (string)$_GET['error'];
    if (isset($errorMap[$code])) {
        $flash = ['type' => 'error', 'msg' => $errorMap[$code]];
    }
}

// Available payment providers, ordered by priority. Always at least
// 'manual'. Stripe-payment plugin (when active) adds 'stripe' at
// priority 10 so it shows first.
$providers = ShopAPI::paymentProviders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'place_order') {
    if (!sf_csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

        // Pick the requested payment method, defaulting to the first
        // available provider (Stripe if it's installed+configured,
        // otherwise manual).
        $methodId = (string)($_POST['payment_method'] ?? '');
        if ($methodId === '' && !empty($providers)) {
            $methodId = $providers[0]['id'];
        }
        $provider = ShopAPI::paymentProvider($methodId);

        if (!$provider) {
            $flash = ['type' => 'error', 'msg' => 'Selected payment method is not available.'];
        } else {
            $res = ($provider['handle_checkout'])($sid, $form);
            if (!empty($res['ok'])) {
                if (!empty($res['redirect'])) {
                    // Off-site payment flow (e.g. Stripe Checkout). Cart
                    // stays intact until success.php confirms payment.
                    header('Location: ' . $res['redirect']);
                    exit;
                }
                if (!empty($res['order_id'])) {
                    $order = ShopAPI::order($res['order_id']);
                    if ($order) {
                        // Use view_token (unguessable) for the confirmation
                        // URL, falling back to order_number only for legacy
                        // rows where the migration backfill hasn't run yet.
                        $key = !empty($order['view_token'])
                             ? $order['view_token']
                             : $order['order_number'];
                        header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
                        exit;
                    }
                }
            }
            $flash = ['type' => 'error', 'msg' => $res['error'] ?? 'Could not place order. Please try again.'];
        }
    }
}

sf_header('Checkout');
?>

<h1 class="sf-h1">Checkout</h1>

<?php if ($flash): ?>
    <div class="sf-flash sf-flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<form method="post">
    <?= sf_csrf_field() ?>
    <input type="hidden" name="_action" value="place_order">
    <div class="sf-checkout-layout">

        <!-- Billing details -->
        <div>
            <div class="sf-card">
                <h2 class="sf-h2">Contact information</h2>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                    <div class="sf-field">
                        <label class="sf-label" for="first_name">First name *</label>
                        <input class="sf-input" type="text" id="first_name" name="first_name"
                               required value="<?= e($form['first_name']) ?>">
                    </div>
                    <div class="sf-field">
                        <label class="sf-label" for="last_name">Last name *</label>
                        <input class="sf-input" type="text" id="last_name" name="last_name"
                               required value="<?= e($form['last_name']) ?>">
                    </div>
                </div>
                <div class="sf-field">
                    <label class="sf-label" for="email">Email *</label>
                    <input class="sf-input" type="email" id="email" name="email"
                           required value="<?= e($form['email']) ?>">
                </div>
            </div>

            <div class="sf-card" style="margin-top:16px;">
                <h2 class="sf-h2">Shipping address</h2>
                <div class="sf-field">
                    <label class="sf-label" for="address_1">Address line 1</label>
                    <input class="sf-input" type="text" id="address_1" name="address_1"
                           value="<?= e($form['address_1']) ?>">
                </div>
                <div class="sf-field">
                    <label class="sf-label" for="address_2">Address line 2 (optional)</label>
                    <input class="sf-input" type="text" id="address_2" name="address_2"
                           value="<?= e($form['address_2']) ?>">
                </div>
                <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:14px;">
                    <div class="sf-field">
                        <label class="sf-label" for="city">City</label>
                        <input class="sf-input" type="text" id="city" name="city"
                               value="<?= e($form['city']) ?>">
                    </div>
                    <div class="sf-field">
                        <label class="sf-label" for="state">State/Province</label>
                        <input class="sf-input" type="text" id="state" name="state"
                               value="<?= e($form['state']) ?>">
                    </div>
                    <div class="sf-field">
                        <label class="sf-label" for="postcode">Postcode</label>
                        <input class="sf-input" type="text" id="postcode" name="postcode"
                               value="<?= e($form['postcode']) ?>">
                    </div>
                </div>
                <div class="sf-field">
                    <label class="sf-label" for="country">Country</label>
                    <input class="sf-input" type="text" id="country" name="country" maxlength="2"
                           placeholder="2-letter code (US, GB, BD, ...)"
                           value="<?= e($form['country']) ?>">
                </div>
                <div class="sf-field" style="margin-bottom:0;">
                    <label class="sf-label" for="notes">Order notes (optional)</label>
                    <textarea class="sf-textarea" id="notes" name="notes" rows="3"
                              placeholder="Anything we should know?"><?= e($form['notes']) ?></textarea>
                </div>
            </div>

            <?php if (count($providers) > 1): // Hide the picker when there's only one option ?>
            <div class="sf-card" style="margin-top:16px;">
                <h2 class="sf-h2">Payment method</h2>
                <div class="sf-payment-methods">
                    <?php $selectedId = (string)($_POST['payment_method'] ?? $providers[0]['id']);
                          foreach ($providers as $p): ?>
                        <label class="sf-payment-option<?= $selectedId === $p['id'] ? ' is-selected' : '' ?>"
                               data-method="<?= e($p['id']) ?>">
                            <input type="radio" name="payment_method"
                                   value="<?= e($p['id']) ?>"
                                   <?= $selectedId === $p['id'] ? 'checked' : '' ?>>
                            <span class="sf-payment-option-body">
                                <span class="sf-payment-option-label"><?= e($p['label']) ?></span>
                                <?php if (!empty($p['description'])): ?>
                                    <span class="sf-payment-option-desc"><?= e($p['description']) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php if (!empty($p['render_form']) && is_callable($p['render_form'])):
                            $extra = ($p['render_form'])();
                            if ($extra !== ''):
                        ?>
                            <div class="sf-payment-option-extra"
                                 data-for="<?= e($p['id']) ?>"
                                 <?= $selectedId === $p['id'] ? '' : 'hidden' ?>>
                                <?= $extra /* trusted: provider-supplied HTML */ ?>
                            </div>
                        <?php endif; endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
            // Toggle .sf-payment-option-extra panels + is-selected class as
            // the customer changes payment method. The PHP-rendered initial
            // state matches whichever radio is checked on page load; this
            // just keeps things in sync on subsequent radio changes.
            (function () {
                var radios = document.querySelectorAll('input[name="payment_method"]');
                var extras = document.querySelectorAll('.sf-payment-option-extra[data-for]');
                var labels = document.querySelectorAll('.sf-payment-option[data-method]');

                function sync() {
                    var sel = document.querySelector('input[name="payment_method"]:checked');
                    if (!sel) return;
                    var id = sel.value;
                    extras.forEach(function (el) {
                        if (el.getAttribute('data-for') === id) {
                            el.hidden = false;
                        } else {
                            el.hidden = true;
                        }
                    });
                    labels.forEach(function (el) {
                        if (el.getAttribute('data-method') === id) {
                            el.classList.add('is-selected');
                        } else {
                            el.classList.remove('is-selected');
                        }
                    });
                }
                radios.forEach(function (r) { r.addEventListener('change', sync); });
            })();
            </script>
            <?php else: ?>
                <?php // Single provider: still need to submit its id ?>
                <input type="hidden" name="payment_method"
                       value="<?= e($providers[0]['id'] ?? 'manual') ?>">
            <?php endif; ?>
        </div>

        <!-- Summary -->
        <div class="sf-summary">
            <div class="sf-card">
                <h2 class="sf-h2">Your order</h2>
                <?php foreach ($cart['items'] as $i): ?>
                    <div class="sf-summary-line">
                        <div style="min-width:0;flex:1;">
                            <span style="color:var(--text)"><?= e($i['product_name']) ?></span>
                            <?php if (!empty($i['variant_value'])): ?>
                                <span class="qty-x">· <?= e($i['variant_value']) ?></span>
                            <?php endif; ?>
                            <span class="qty-x">× <?= (int)$i['qty'] ?></span>
                        </div>
                        <div style="font-weight:500;color:var(--text);font-variant-numeric:tabular-nums;">
                            <?= e(sf_money((float)$i['unit_price'] * (int)$i['qty'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="sf-summary-line">
                    <span>Subtotal</span><span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['subtotal'])) ?></span>
                </div>
                <?php if ($cart['shipping'] > 0): ?>
                    <div class="sf-summary-line">
                        <span>Shipping</span><span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['shipping'])) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($cart['tax'] > 0): ?>
                    <div class="sf-summary-line">
                        <span>Tax</span><span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['tax'])) ?></span>
                    </div>
                <?php endif; ?>
                <div class="sf-summary-line total">
                    <span>Total</span><span style="font-variant-numeric:tabular-nums;"><?= e(sf_money($cart['total'])) ?></span>
                </div>
                <button type="submit" class="sf-btn sf-btn-primary sf-btn-block" style="margin-top:18px;">
                    Place order
                </button>
                <p class="sf-summary-note">You'll receive an order confirmation email.</p>
            </div>
        </div>
    </div>
</form>

<?php sf_footer(); ?>
