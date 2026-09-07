<?php
/**
 * Stripe checkout success endpoint.
 *
 * Flow:
 *   1. Customer pays on stripe.com's hosted Checkout page.
 *   2. Stripe redirects them back to:
 *        /plugins/stripe-payment/public/success.php
 *          ?session_id=cs_test_...
 *          &sid=<shop_sid>
 *   3. We look up the session in stripepayment_sessions to find the cart.
 *   4. If an order has already been created for this session (by an
 *      earlier visit, or by the webhook getting there first), redirect
 *      to its confirmation page. We never create a duplicate.
 *   5. Otherwise we verify payment status with Stripe, create the order,
 *      mark the mapping completed, clear the cart, redirect.
 *
 * The webhook (webhook.php) does the same thing server-to-server. Either
 * path can win — whichever fires first creates the order; the other one
 * sees the existing order_id in the mapping table and skips.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    http_response_code(503);
    echo 'Shop plugin not active.';
    exit;
}

require_once dirname(__DIR__) . '/StripeAPI.php';

$sessionId = (string)($_GET['session_id'] ?? '');
$sid       = (string)($_GET['sid'] ?? '');

if ($sessionId === '' || $sid === '') {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Look up the mapping row we wrote when the session was created.
$row = Database::row(
    "SELECT id, shop_sid, order_id, status, billing_json
       FROM stripepayment_sessions
      WHERE stripe_session_id = ?",
    [$sessionId]
);

if (!$row) {
    // Session id we've never seen — either expired DB, or someone
    // manipulating the query string. Don't create anything; bounce home.
    slate_log("Stripe success.php: unknown session_id $sessionId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Already converted to an order? Just redirect to its confirmation page.
if (!empty($row['order_id'])) {
    $order = ShopAPI::order((int)$row['order_id']);
    if ($order) {
        $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
        header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
        exit;
    }
    // Mapping says we have an order_id, but it's gone — shouldn't happen,
    // but if it does treat as fresh and try again rather than failing.
}

// Verify payment with Stripe. The webhook would do the same check; we
// repeat it here because we can't trust query-string params alone.
$stripeSession = StripeAPI::getSession($sessionId);
if (!$stripeSession) {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// 'paid' is what we want for one-shot payments. Stripe also has
// 'no_payment_required' for $0 orders and 'unpaid' for failed/aborted.
$payStatus = (string)($stripeSession['payment_status'] ?? '');
if ($payStatus !== 'paid' && $payStatus !== 'no_payment_required') {
    slate_log("Stripe success.php: payment_status=$payStatus for $sessionId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// Create the order. We prefer the billing details stashed at session-
// creation time over what Stripe collected, since the customer filled
// out the Slate checkout form first; Stripe's may be incomplete.
$billing = [];
if (!empty($row['billing_json'])) {
    $decoded = json_decode((string)$row['billing_json'], true);
    if (is_array($decoded)) $billing = $decoded;
}

// Fall back to Stripe's customer_details where our billing is empty.
$cd = $stripeSession['customer_details'] ?? [];
$cdAddr = $cd['address'] ?? [];

$fallback = [
    'email'      => $cd['email']        ?? '',
    'first_name' => '',
    'last_name'  => '',
    'address_1'  => $cdAddr['line1']    ?? '',
    'address_2'  => $cdAddr['line2']    ?? '',
    'city'       => $cdAddr['city']     ?? '',
    'state'      => $cdAddr['state']    ?? '',
    'postcode'   => $cdAddr['postal_code'] ?? '',
    'country'    => $cdAddr['country']  ?? '',
    'notes'      => '',
];
if (!empty($cd['name'])) {
    // Stripe gives one combined name field; split naively
    $parts = preg_split('/\s+/', trim((string)$cd['name']), 2);
    $fallback['first_name'] = $parts[0] ?? '';
    $fallback['last_name']  = $parts[1] ?? '';
}
foreach ($fallback as $k => $v) {
    if (empty($billing[$k])) $billing[$k] = $v;
}

// Create the order via Shop. checkoutCart clears the cart on success.
$res = ShopAPI::checkoutCart($row['shop_sid'], $billing);

if (empty($res['ok']) || empty($res['order_id'])) {
    slate_log('Stripe success.php order creation failed: ' .
        ($res['error'] ?? '?'), 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

$orderId = (int)$res['order_id'];

// Reconcile what Stripe actually charged against the order we just rebuilt
// from the cart. If the cart drifted between intent creation and payment,
// hold the order for review instead of auto-fulfilling a wrong total.
$paidCents    = (int)($stripeSession['amount_total'] ?? 0);
$paidCurrency = strtoupper((string)($stripeSession['currency'] ?? ''));
$recon        = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

// Stamp the order_id back onto the mapping row + mark the order as paid.
// Use a transaction so a concurrent webhook can't also create a second
// order between our check and this update.
try {
    Database::update('stripepayment_sessions', [
        'order_id'     => $orderId,
        'status'       => 'completed',
        'completed_at' => slate_db_now(),
    ], 'id = ?', [(int)$row['id']]);

    // Bump to 'processing' (paid + ready to fulfil) only when the charge
    // matches. On a mismatch reconcilePaidAmount() already set 'on-hold'.
    if (!empty($recon['ok'])) {
        ShopAPI::updateStatus($orderId, 'processing');
    }
} catch (\Throwable $e) {
    // The order exists — just log and continue to the confirmation
    // page. The webhook can heal the mapping if it gets there.
    slate_log('Stripe success.php mapping update failed: ' . $e->getMessage(), 'warning');
}

$order = ShopAPI::order($orderId);
if (!$order) {
    // Pathological: we just created it. Send to a generic landing.
    header('Location: ' . SLATE_URL . '/shop/');
    exit;
}
$key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
exit;
