<?php
/**
 * Return URL for the embedded Payment Element flow.
 *
 * Stripe's `stripe.confirmPayment({redirect: 'if_required'})` mostly
 * handles 3D Secure as a modal popup on the customer's current page.
 * But for some payment methods (notably certain bank-redirect 3DS
 * flows in Europe and some Indian card networks), Stripe MUST
 * redirect the customer's browser to an authorisation page. After
 * authorisation, the bank redirects back here with two query params:
 *
 *   ?payment_intent=pi_xxx
 *   &payment_intent_client_secret=pi_xxx_secret_yyy
 *
 * Our job: look up the mapping row by PaymentIntent id, check the
 * final status, then send the customer to either:
 *   - the order confirmation page (success), or
 *   - back to /shop/checkout with an error (failure / cancelled)
 *
 * The webhook (payment_intent.succeeded) is the source of truth for
 * order creation. If it fired before this return-URL hit, the order
 * is already in the DB and we just redirect to confirmation. If it
 * hasn't fired yet, we wait a moment and check again, then either
 * create the order ourselves or bounce to checkout.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    http_response_code(503);
    echo 'Shop plugin not active.';
    exit;
}

require_once dirname(__DIR__) . '/StripeAPI.php';

$piId = (string)($_GET['payment_intent'] ?? '');
if ($piId === '') {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Look up our mapping row. If we've never seen this PI id, treat as
// a redirect from a forged/expired URL.
$row = Database::row(
    "SELECT id, shop_sid, order_id, billing_json, status
       FROM stripepayment_sessions
      WHERE payment_intent_id = ?",
    [$piId]
);
if (!$row) {
    slate_log("Stripe return.php: unknown payment_intent_id $piId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Bind to the browser that created this PaymentIntent. The shop_sid
// cookie rides along on the bank's top-level redirect (SameSite=Lax),
// so a legitimate return matches. A request carrying a guessed or leaked
// pi_id from a different session must not create orders, clear another
// session's cart, or reveal someone else's confirmation page.
$browserSid = (string)($_COOKIE['shop_sid'] ?? '');
if ($browserSid === '' || !hash_equals((string)$row['shop_sid'], $browserSid)) {
    slate_log("Stripe return.php: shop_sid mismatch on $piId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Already converted by the webhook or earlier visit? Just redirect.
if (!empty($row['order_id'])) {
    $order = ShopAPI::order((int)$row['order_id']);
    if ($order) {
        $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
        header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
        exit;
    }
}

// Verify the PaymentIntent status with Stripe. If the bank redirect
// was a success, status is 'succeeded'. If the customer cancelled,
// status stays at 'requires_payment_method' or 'requires_action'.
try {
    $pi = StripeAPI::getPaymentIntent($piId);
} catch (\Throwable $e) {
    slate_log('Stripe return.php getPaymentIntent failed: ' . $e->getMessage(), 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

$status = (string)($pi['status'] ?? '');
if ($status !== 'succeeded' && $status !== 'requires_capture') {
    // Payment didn't go through. Cart is still intact (we didn't
    // clear it yet); customer can retry.
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// Payment succeeded server-side but webhook hasn't created the order
// yet. Create it ourselves — same idempotent path the webhook uses.
$billing = [];
if (!empty($row['billing_json'])) {
    $decoded = json_decode((string)$row['billing_json'], true);
    if (is_array($decoded)) $billing = $decoded;
}

// Fall back to Stripe's receipt_email if we have nothing else (rare —
// the customer normally filled out the Slate form first).
if (empty($billing['email']) && !empty($pi['receipt_email'])) {
    $billing['email'] = (string)$pi['receipt_email'];
}

if (empty($billing['email'])) {
    slate_log("Stripe return.php: no billing on $piId", 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

$res = ShopAPI::checkoutCart($row['shop_sid'], $billing);
if (empty($res['ok']) || empty($res['order_id'])) {
    slate_log('Stripe return.php order creation failed: ' . ($res['error'] ?? '?'), 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

$orderId = (int)$res['order_id'];

// Reconcile the captured amount against the rebuilt order total; park
// on-hold on a mismatch instead of auto-fulfilling (mirrors success.php).
$paidCents    = (int)($pi['amount_received'] ?? $pi['amount'] ?? 0);
$paidCurrency = strtoupper((string)($pi['currency'] ?? ''));
$recon        = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

try {
    Database::update('stripepayment_sessions', [
        'order_id'     => $orderId,
        'status'       => 'completed',
        'completed_at' => slate_db_now(),
    ], 'id = ?', [(int)$row['id']]);
    if (!empty($recon['ok'])) {
        ShopAPI::updateStatus($orderId, 'processing');
    }
} catch (\Throwable $e) {
    slate_log('Stripe return.php mapping update failed: ' . $e->getMessage(), 'warning');
}

$order = ShopAPI::order($orderId);
if (!$order) {
    header('Location: ' . SLATE_URL . '/shop/');
    exit;
}
$key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
exit;
