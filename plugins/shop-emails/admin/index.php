<?php
/**
 * Shop emails — admin page.
 *
 * Three things here:
 *   1. Set the admin-notification recipient address.
 *   2. Override the default template subject/body for each of the
 *      four email events (customer_created, customer_paid,
 *      customer_completed, admin_created).
 *   3. Send a test email with a synthetic order so admins can verify
 *      the SMTP path works AND that the template renders properly
 *      before a real customer's order silently fails.
 *
 * No new DB table — everything goes in the `settings` table with
 * keys prefixed `shop_email.`.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop_emails.manage');

require_once dirname(__DIR__) . '/ShopEmails.php';

$pageTitle  = __('shop_emails', 'Email templates');
$currentNav = 'shop-emails';

$tid   = current_tenant_id();
$flash = null;

// The four templates we manage. The same keys are used by
// ShopEmails::getTemplate() at send time.
$templates = [
    'customer_created'   => 'Customer — order received',
    'customer_paid'      => 'Customer — payment confirmed',
    'customer_completed' => 'Customer — order completed',
    'admin_created'      => 'Admin — new order notification',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'save_recipient') {
            // Admin recipient. Empty = use SMTP from-address fallback.
            $addr = trim((string)($_POST['admin_to'] ?? ''));
            if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error',
                    'msg' => __('email_invalid', 'Enter a valid email address (or leave blank).')];
            } else {
                Database::setSetting('shop_email.admin_to', $addr);
                AuditLog::record('shop_emails.recipient_updated', '', ['to' => $addr]);
                $flash = ['type' => 'success', 'msg' => __('saved', 'Saved.')];
            }
        }

        elseif ($action === 'save_template') {
            $key = (string)($_POST['template_key'] ?? '');
            if (!isset($templates[$key])) {
                $flash = ['type' => 'error', 'msg' => __('unknown_template', 'Unknown template.')];
            } else {
                // Allow empty subject/body to mean "reset to default" —
                // store empty string so getTemplate() falls through to
                // the hardcoded default. (We could DELETE the setting
                // row instead but setSetting() is upsert-only.)
                $subject = trim((string)($_POST['subject'] ?? ''));
                $body    = (string)($_POST['body'] ?? '');
                // Cap stored length so a runaway paste can't bloat the
                // settings table. 64KB body is plenty for any sane
                // email template.
                if (mb_strlen($subject) > 500) $subject = mb_substr($subject, 0, 500);
                if (mb_strlen($body)    > 65000) $body = mb_substr($body, 0, 65000);
                Database::setSetting("shop_email.tpl_{$key}_subject", $subject);
                Database::setSetting("shop_email.tpl_{$key}_body",    $body);
                AuditLog::record('shop_emails.template_updated', $key);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('template_saved', 'Template "%s" saved.'), $templates[$key])];
            }
        }

        elseif ($action === 'reset_template') {
            $key = (string)($_POST['template_key'] ?? '');
            if (isset($templates[$key])) {
                Database::setSetting("shop_email.tpl_{$key}_subject", '');
                Database::setSetting("shop_email.tpl_{$key}_body",    '');
                AuditLog::record('shop_emails.template_reset', $key);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('template_reset', 'Template "%s" reset to default.'), $templates[$key])];
            }
        }

        elseif ($action === 'send_test') {
            $to = trim((string)($_POST['test_to'] ?? ''));
            $key = (string)($_POST['template_key'] ?? 'customer_created');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => __('email_invalid', 'Enter a valid email address.')];
            } elseif (!isset($templates[$key])) {
                $flash = ['type' => 'error', 'msg' => __('unknown_template', 'Unknown template.')];
            } else {
                // Try to use a real recent order so the test reflects
                // real data. Fall back to a synthetic one if there are
                // no orders yet.
                $sampleOrder = Database::row(
                    "SELECT * FROM shop_orders WHERE tenant_id = ?
                      ORDER BY created_at DESC LIMIT 1",
                    [$tid]
                );
                if ($sampleOrder) {
                    $sampleOrder['items'] = Database::rows(
                        "SELECT * FROM shop_order_items WHERE order_id = ?",
                        [(int)$sampleOrder['id']]
                    );
                } else {
                    // Synthetic fallback so the test works on a fresh
                    // install with zero orders.
                    $sampleOrder = makeSyntheticSampleOrder($tid);
                }
                // Override recipient: send to wherever the admin typed,
                // NOT the real customer of the sampled order. We just
                // render templates the same way the runtime does and
                // hand the result to Mailer::send().
                $subjectTpl = ShopEmails::getTemplate($key, 'subject');
                $bodyTpl    = ShopEmails::getTemplate($key, 'body');
                $rendered = renderPreviewFromOrder($sampleOrder, $subjectTpl, $bodyTpl);

                $ok = Mailer::send($to, '[TEST] ' . $rendered['subject'], $rendered['body']);
                if ($ok) {
                    AuditLog::record('shop_emails.test_sent', $to, ['template' => $key]);
                    $flash = ['type' => 'success', 'msg' => sprintf(
                        __('test_sent', 'Test email sent to %s.'), $to)];
                } else {
                    $flash = ['type' => 'error', 'msg' => __('test_failed',
                        'Test email could not be sent. Check SMTP settings and audit log.')];
                }
            }
        }
    }
}

// ──────────────────────────────────────────────────────────
// Sample-order helpers (kept top-level so the POST block can use them)
// ──────────────────────────────────────────────────────────

function makeSyntheticSampleOrder(int $tenantId): array {
    return [
        'id'              => 0,
        'tenant_id'       => $tenantId,
        'order_number'    => 'TEST-00001',
        'view_token'      => str_repeat('0', 32),
        'customer_email'  => 'sample@example.com',
        'customer_name'   => 'Sample Customer',
        'status'          => 'pending',
        'subtotal'        => 42.00,
        'discount_total'  => 0.00,
        'shipping_total'  => 5.00,
        'tax_total'       => 4.20,
        'total'           => 51.20,
        'currency'        => Database::setting('shop.currency') ?: 'USD',
        'coupon_code'     => '',
        'ship_address_1'  => '123 Sample St',
        'ship_address_2'  => 'Apt 4',
        'ship_city'       => 'Springfield',
        'ship_state'      => 'IL',
        'ship_postcode'   => '62701',
        'ship_country'    => 'US',
        'created_at'      => slate_db_now(),
        'items'           => [
            ['product_name' => 'Sample product', 'product_sku' => 'SKU-001',
             'quantity' => 2, 'unit_price' => 21.00, 'line_total' => 42.00],
        ],
    ];
}

/**
 * One-shot template rendering for the preview/test path. Mirrors
 * ShopEmails' production rendering so previews don't drift from
 * what customers get.
 *
 * The intentional duplication is so the runtime rendering in
 * ShopEmails stays private (less surface, fewer accidental
 * call-sites). If templates ever grow conditionals/loops, fold
 * both paths back into one.
 */
function renderPreviewFromOrder(array $order, string $subjectTpl, string $bodyTpl): array {
    $siteName = Database::setting('site_name') ?: 'Shop';
    $shopUrl  = SLATE_URL . '/shop/';
    $orderKey = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
    $orderUrl = SLATE_URL . '/shop/order?key=' . urlencode($orderKey);
    $adminUrl = SLATE_URL . '/plugins/shop/admin/orders.php?id=' . (int)$order['id'];

    $items = $order['items'] ?? [];
    $currency = $order['currency'] ?? 'USD';

    // Build items HTML — slightly simplified compared to the runtime
    // (no auto- SKU hiding) since this is for preview only.
    $itemsHtml = '<table cellpadding="6" cellspacing="0" border="0" '
               . 'style="width:100%; border-collapse:collapse; border-bottom:1px solid #ddd; '
               . 'font-family:sans-serif; font-size:14px;">';
    $itemsHtml .= '<thead><tr style="border-bottom:1px solid #ddd; text-align:left;">';
    $itemsHtml .= '<th>Item</th><th style="text-align:center;width:60px;">Qty</th>';
    $itemsHtml .= '<th style="text-align:right;width:100px;">Total</th></tr></thead><tbody>';
    foreach ($items as $i) {
        $itemsHtml .= '<tr><td>' . e((string)($i['product_name'] ?? ''));
        if (!empty($i['product_sku'])) {
            $itemsHtml .= '<br><span style="color:#888;font-size:12px;">SKU: '
                       . e((string)$i['product_sku']) . '</span>';
        }
        $itemsHtml .= '</td><td style="text-align:center;">' . (int)($i['quantity'] ?? 0) . '</td>';
        $itemsHtml .= '<td style="text-align:right;">'
                   . e(ShopAPI::formatMoney((float)($i['line_total'] ?? 0), $currency))
                   . '</td></tr>';
    }
    $itemsHtml .= '</tbody></table>';

    $addrParts = array_filter([
        trim((string)($order['ship_address_1'] ?? '')),
        trim((string)($order['ship_address_2'] ?? '')),
        trim(implode(' ', array_filter([
            $order['ship_city'] ?? '', $order['ship_state'] ?? '', $order['ship_postcode'] ?? '',
        ]))),
        trim((string)($order['ship_country'] ?? '')),
    ]);
    $shipAddr = implode("<br>\n", array_map('e', $addrParts));

    $context = [
        'site_name'      => e((string)$siteName),
        'shop_url'       => e($shopUrl),
        'order_number'   => e((string)$order['order_number']),
        'order_url'      => e($orderUrl),
        'admin_order_url'=> e($adminUrl),
        'customer_name'  => e((string)($order['customer_name'] ?? '')),
        'customer_email' => e((string)($order['customer_email'] ?? '')),
        'status'         => e((string)($order['status'] ?? '')),
        'currency'       => e((string)$currency),
        'subtotal'       => e(ShopAPI::formatMoney((float)($order['subtotal'] ?? 0), $currency)),
        'discount'       => e(ShopAPI::formatMoney((float)($order['discount_total'] ?? 0), $currency)),
        'shipping'       => e(ShopAPI::formatMoney((float)($order['shipping_total'] ?? 0), $currency)),
        'tax'            => e(ShopAPI::formatMoney((float)($order['tax_total'] ?? 0), $currency)),
        'total'          => e(ShopAPI::formatMoney((float)($order['total'] ?? 0), $currency)),
        'coupon_code'    => e((string)($order['coupon_code'] ?? '')),
        'items_html'     => $itemsHtml,
        'shipping_addr_html' => $shipAddr,
        'created_at'     => e((string)($order['created_at'] ?? '')),
    ];

    $search  = array_map(fn($k) => '{' . $k . '}', array_keys($context));
    $replace = array_values($context);

    return [
        'subject' => str_replace($search, $replace, $subjectTpl),
        'body'    => str_replace($search, $replace, $bodyTpl),
    ];
}

// ──────────────────────────────────────────────────────────
// Load current values
// ──────────────────────────────────────────────────────────

$adminTo = (string)(Database::setting('shop_email.admin_to') ?: '');

$current = [];
foreach (array_keys($templates) as $k) {
    $current[$k] = [
        'subject' => (string)(Database::setting("shop_email.tpl_{$k}_subject") ?: ''),
        'body'    => (string)(Database::setting("shop_email.tpl_{$k}_body")    ?: ''),
    ];
}

$defaults = ShopEmails::allDefaultTemplates();

require $root . '/admin/partials/header.php';

if (function_exists('slate_breadcrumbs')) {
    slate_breadcrumbs([
        ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
        ['label' => __('shop_emails', 'Email templates')],
    ]);
}
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><?= __('shop_emails', 'Shop email templates') ?></h1>
        <p class="page-header-sub">
            <?= __('shop_emails_sub',
                'Configure the automated emails sent when orders are placed and statuses change. Templates use {placeholders} that are filled in at send time. Leave subject/body blank to use the built-in default.') ?>
        </p>
    </div>
</div>

<!-- Admin recipient -->
<div class="card">
    <div class="card-header"><h2><?= __('admin_recipient', 'Admin notification recipient') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_recipient">
        <div class="field">
            <label class="field-label" for="admin_to"><?= __('email_address', 'Email address') ?></label>
            <input type="email" id="admin_to" name="admin_to"
                   value="<?= e($adminTo) ?>"
                   placeholder="<?= e(__('admin_to_placeholder', 'you@yourstore.com')) ?>">
            <div class="field-hint">
                <?= __('admin_to_hint',
                    'Where new-order notifications are sent. Leave blank to fall back to the SMTP from-address in Slate → Settings → Email.') ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('save', 'Save') ?></button>
    </form>
</div>

<!-- Available placeholders reference -->
<div class="card">
    <div class="card-header"><h2><?= __('placeholders', 'Available placeholders') ?></h2></div>
    <p class="text-muted text-sm">
        <?= __('placeholders_hint',
            'Use these inside subject or body. Unknown placeholders are left as-is so typos are visible.') ?>
    </p>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:6px 24px; font-family:monospace; font-size:13px;">
        <div><code>{site_name}</code> — your store name</div>
        <div><code>{shop_url}</code> — link to the storefront</div>
        <div><code>{order_number}</code> — e.g. ORD-00042</div>
        <div><code>{order_url}</code> — customer order link</div>
        <div><code>{admin_order_url}</code> — admin order page</div>
        <div><code>{customer_name}</code></div>
        <div><code>{customer_email}</code></div>
        <div><code>{status}</code> — pending / processing / etc</div>
        <div><code>{currency}</code> — e.g. USD</div>
        <div><code>{subtotal}</code> — formatted with symbol</div>
        <div><code>{discount}</code></div>
        <div><code>{shipping}</code></div>
        <div><code>{tax}</code></div>
        <div><code>{total}</code></div>
        <div><code>{coupon_code}</code></div>
        <div><code>{items_html}</code> — the line-items table</div>
        <div><code>{shipping_addr_html}</code></div>
        <div><code>{created_at}</code> — order timestamp</div>
    </div>
</div>

<!-- Template editors -->
<?php foreach ($templates as $key => $label):
    $isOverridden = ($current[$key]['subject'] !== '' || $current[$key]['body'] !== '');
    $effectiveSubject = $current[$key]['subject'] !== ''
        ? $current[$key]['subject']
        : $defaults[$key]['subject'];
    $effectiveBody = $current[$key]['body'] !== ''
        ? $current[$key]['body']
        : $defaults[$key]['body'];
?>
    <details class="card">
        <summary style="cursor:pointer; padding:0.75rem; list-style:none; display:flex; align-items:center; gap:0.75rem;">
            <span style="flex:1; font-weight:600;"><?= e($label) ?></span>
            <?php if ($isOverridden): ?>
                <span class="badge badge-info"><?= __('overridden', 'Overridden') ?></span>
            <?php else: ?>
                <span class="badge badge-muted"><?= __('default', 'Default') ?></span>
            <?php endif; ?>
            <span class="text-muted text-sm">click to edit</span>
        </summary>
        <div style="padding:0.75rem; border-top:1px solid var(--border, #e5e9f0);">
            <form method="post" style="margin-bottom:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_template">
                <input type="hidden" name="template_key" value="<?= e($key) ?>">

                <div class="field">
                    <label class="field-label"><?= __('subject', 'Subject') ?></label>
                    <input type="text" name="subject" maxlength="500"
                           value="<?= e($effectiveSubject) ?>">
                </div>

                <div class="field">
                    <label class="field-label"><?= __('body', 'Body (HTML)') ?></label>
                    <textarea name="body" rows="14"
                              style="font-family:monospace; font-size:12.5px; line-height:1.5;"><?= e($effectiveBody) ?></textarea>
                    <div class="field-hint">
                        <?= __('body_hint',
                            'HTML allowed. Use inline styles (not <style> blocks) for best email-client compatibility.') ?>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary"><?= __('save_template', 'Save template') ?></button>
                </div>
            </form>

            <?php if ($isOverridden): ?>
                <form method="post"
                      onsubmit="return confirm(<?= e(json_encode(
                          sprintf(__('confirm_reset', 'Reset "%s" to the default template?'), $label)
                      )) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="reset_template">
                    <input type="hidden" name="template_key" value="<?= e($key) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">
                        <?= __('reset_to_default', 'Reset to default') ?>
                    </button>
                </form>
            <?php endif; ?>

            <hr style="margin:1rem 0; border:none; border-top:1px solid var(--border, #e5e9f0);">

            <!-- Send-test sub-form -->
            <form method="post" class="flex gap-2" style="align-items:flex-end;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="send_test">
                <input type="hidden" name="template_key" value="<?= e($key) ?>">
                <div class="field" style="flex:1; margin:0;">
                    <label class="field-label" style="font-size:11px;">
                        <?= __('send_test_to', 'Send test to') ?>
                    </label>
                    <input type="email" name="test_to" placeholder="you@example.com">
                </div>
                <button type="submit" class="btn">
                    <?= __('send_test', 'Send test') ?>
                </button>
            </form>
            <div class="text-muted text-sm" style="margin-top:0.4rem;">
                <?= __('send_test_hint',
                    'Uses the most recent real order if you have one; otherwise a synthetic sample. Subject gets a [TEST] prefix.') ?>
            </div>
        </div>
    </details>
<?php endforeach; ?>

<!-- Operational notes -->
<div class="card">
    <div class="card-header"><h2><?= __('operational_notes', 'Notes') ?></h2></div>
    <ul style="margin:0; padding-left:1.2rem; line-height:1.7;">
        <li><?= __('note_smtp',
            'SMTP must be configured in Slate → Settings → Email for any of these to send. Without it, Slate falls back to PHP\'s mail() function which most hosts block.') ?></li>
        <li><?= __('note_audit',
            'Every send (success or failure) is recorded to the audit log under the mail.send event with the SMTP error if any.') ?></li>
        <li><?= __('note_silent',
            'If you suspect emails aren\'t arriving, send a test from above first. If the test succeeds but real orders don\'t generate emails, check the audit log for shop_order_created entries.') ?></li>
        <li><?= __('note_paid_email',
            'The "payment confirmed" email fires when an order transitions to status processing. With Stripe, that happens via the webhook. With manual payment, it never fires unless an admin changes status manually.') ?></li>
        <li><?= __('note_no_queue',
            'No retry queue: if SMTP times out, the email is lost (the order is not). For ~20 orders/day this is acceptable; if you start sending hundreds, replace this plugin with a queued version.') ?></li>
    </ul>
</div>

<?php require $root . '/admin/partials/footer.php'; ?>
