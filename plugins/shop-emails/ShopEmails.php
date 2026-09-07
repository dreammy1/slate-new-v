<?php
/**
 * Shop email notifications.
 *
 * Listens for Shop order events and sends emails. Three triggers:
 *
 *   shop_order_created          → customer confirmation + admin notification
 *   shop_order_status_changed   → customer payment-completed (when status
 *                                 transitions to 'processing')
 *
 * Templates live in the `settings` table under keys like
 * `shop_email.tpl_customer_created_subject`. If a key isn't set, the
 * hardcoded default in defaultTemplate() is used. This lets admins
 * customise without us needing to ship an editor with full template
 * features — they just paste HTML into the admin/index.php form.
 *
 * Variable substitution is a flat str_replace pass over the placeholder
 * list returned by buildContext(). No conditionals, no loops in
 * templates beyond what we render server-side into {items_html}. Keep
 * it boring.
 *
 * Failure handling: every send goes through Mailer::send() which
 * already records to AuditLog. We additionally slate_log() the
 * specific event + recipient on failure so an operator tailing
 * data/slate.log can see which customer didn't get their email.
 * No queue or retry — for ~20 orders/day with reasonable SMTP this
 * is fine; if you start sending thousands, replace this plugin with
 * a queued one (drop-in replacement on the same hooks).
 */

class ShopEmails extends Plugin {

    public function boot(): void {
        // Listener: order just created. ShopAPI fires this AFTER the
        // transaction commits, so the order is definitely in the DB
        // by the time we're called.
        Hook::addAction('shop_order_created', [$this, 'onOrderCreated'], 10, 2);

        // Listener: status changed. We send the paid email only on
        // transition to 'processing' (which is what Stripe's webhook
        // sets after a successful charge).
        Hook::addAction('shop_order_status_changed', [$this, 'onStatusChanged'], 10, 2);

        // Admin nav under Shop.
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('shop_emails.manage') && !Auth::isSuperAdmin()) {
            return $items;
        }
        $items[] = [
            'slug'   => 'shop-emails',
            'label'  => __('shop_emails', 'Email templates'),
            'href'   => $this->url('admin/index.php'),
            'icon'   => 'send',
            'order'  => 517,
            'group'  => 'shop',
        ];
        return $items;
    }

    // ──────────────────────────────────────────────────────────
    // Event handlers
    // ──────────────────────────────────────────────────────────

    /**
     * shop_order_created handler.
     *
     * Signature matches what ShopAPI::createOrder() calls:
     *   Hook::doAction('shop_order_created', $orderId, $customerEmail);
     *
     * We send TWO emails: one to the customer, one to the admin.
     * Failures of either are logged but don't stop the other.
     */
    public function onOrderCreated(int $orderId, string $customerEmail = ''): void {
        $order = ShopAPI::order($orderId);
        if (!$order) {
            slate_log("shop-emails: order #$orderId not found for onOrderCreated", 'warning');
            return;
        }

        // Customer confirmation
        $this->sendForTemplate('customer_created', $order, $order['customer_email']);

        // Admin notification
        $adminTo = $this->adminRecipient();
        if ($adminTo !== '') {
            $this->sendForTemplate('admin_created', $order, $adminTo);
        }
    }

    /**
     * shop_order_status_changed handler.
     *
     * Signature:
     *   Hook::doAction('shop_order_status_changed', $orderId, $newStatus);
     *
     * We only act on the 'processing' transition (Stripe webhook
     * confirms payment) and the 'completed' transition (admin marked
     * it shipped/done). Other transitions (cancelled, refunded,
     * on-hold) we leave silent — those usually involve back-and-forth
     * the admin will handle by hand.
     */
    public function onStatusChanged(int $orderId, string $newStatus = ''): void {
        if ($newStatus !== 'processing' && $newStatus !== 'completed') {
            return;
        }
        $order = ShopAPI::order($orderId);
        if (!$order) return;

        $templateKey = $newStatus === 'processing'
            ? 'customer_paid'
            : 'customer_completed';

        $this->sendForTemplate($templateKey, $order, $order['customer_email']);
    }

    // ──────────────────────────────────────────────────────────
    // Sending
    // ──────────────────────────────────────────────────────────

    private function sendForTemplate(string $key, array $order, string $to): void {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            slate_log("shop-emails: skipping $key — invalid recipient '$to' for order #{$order['id']}", 'warning');
            return;
        }

        $context = $this->buildContext($order);
        $subject = $this->render(self::getTemplate($key, 'subject'), $context);
        $bodyHtml = $this->render(self::getTemplate($key, 'body'), $context);

        $ok = Mailer::send($to, $subject, $bodyHtml);
        if (!$ok) {
            slate_log(
                "shop-emails: $key send failed for order #{$order['id']} to $to "
              . '(see audit log for SMTP error detail)',
                'error'
            );
        }
    }

    // ──────────────────────────────────────────────────────────
    // Template lookup
    // ──────────────────────────────────────────────────────────

    /**
     * Fetch a template piece (subject | body) for a key, falling back
     * to the hardcoded default. The settings-table key is namespaced
     * so admins can paste custom HTML through the admin page without
     * touching code.
     *
     * Static because there's no instance state — the lookup only
     * needs the key and reads from Database::setting(). Being static
     * also lets the admin/index.php preview path call this without
     * trying to construct a Plugin (which requires manifest + rootDir
     * we don't have at that call site).
     *
     * Key layout:  shop_email.tpl_{key}_{part}    e.g.
     *              shop_email.tpl_customer_created_subject
     *              shop_email.tpl_customer_created_body
     */
    public static function getTemplate(string $key, string $part): string {
        $settingKey = "shop_email.tpl_{$key}_{$part}";
        $stored = Database::setting($settingKey);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        return self::defaultTemplate($key, $part);
    }

    /**
     * Admin notification recipient. Falls back to the SMTP from-address
     * if not explicitly set — better than nothing.
     */
    public function adminRecipient(): string {
        $explicit = Database::setting('shop_email.admin_to');
        if (is_string($explicit) && filter_var($explicit, FILTER_VALIDATE_EMAIL)) {
            return $explicit;
        }
        $fallback = Database::setting('smtp_from_email');
        if (is_string($fallback) && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return $fallback;
        }
        return '';
    }

    // ──────────────────────────────────────────────────────────
    // Template rendering
    // ──────────────────────────────────────────────────────────

    /**
     * Render a template by replacing {placeholders} from $context.
     *
     * Unknown placeholders are left as-is (rather than blanked) so
     * admins typing a custom template see their typo on the next test
     * email, instead of just getting silent empty strings.
     */
    private function render(string $template, array $context): string {
        $search  = [];
        $replace = [];
        foreach ($context as $k => $v) {
            $search[]  = '{' . $k . '}';
            $replace[] = (string)$v;
        }
        return str_replace($search, $replace, $template);
    }

    /**
     * Build the placeholder context for an order. Everything that
     * goes into the customer's email is here. Sensitive fields
     * (anything Stripe-specific, internal ids) are deliberately NOT
     * exposed.
     *
     * Values are pre-escaped where appropriate. The body template is
     * HTML, so customer-supplied fields (name, address) are run
     * through e() to prevent injection from a customer who put
     * <script> in the shipping address field.
     */
    private function buildContext(array $order): array {
        $siteName = Database::setting('site_name') ?: 'Shop';
        $shopUrl  = SLATE_URL . '/shop/';
        $orderKey = !empty($order['view_token'])
                  ? $order['view_token']
                  : $order['order_number'];
        $orderUrl = SLATE_URL . '/shop/order?key=' . urlencode($orderKey);
        $adminUrl = SLATE_URL . '/plugins/shop/admin/orders.php?id=' . (int)$order['id'];

        // Build the line-items HTML block. We do this server-side
        // rather than as a {loop} placeholder because str_replace
        // doesn't loop and we said no Twig.
        $itemsHtml = $this->renderItemsHtml($order);

        $currency = $order['currency'] ?? (Database::setting('shop.currency') ?: 'USD');

        // Shipping address — a single line, only the parts present.
        $addrParts = array_filter([
            trim((string)($order['ship_address_1'] ?? '')),
            trim((string)($order['ship_address_2'] ?? '')),
            trim(implode(' ', array_filter([
                $order['ship_city']     ?? '',
                $order['ship_state']    ?? '',
                $order['ship_postcode'] ?? '',
            ]))),
            trim((string)($order['ship_country'] ?? '')),
        ]);
        $shipAddr = implode("<br>\n", array_map('e', $addrParts));

        return [
            'site_name'      => e((string)$siteName),
            'shop_url'       => e($shopUrl),
            'order_number'   => e((string)$order['order_number']),
            'order_url'      => e($orderUrl),
            'admin_order_url'=> e($adminUrl),
            'customer_name'  => e((string)($order['customer_name'] ?? '')),
            'customer_email' => e((string)($order['customer_email'] ?? '')),
            'status'         => e((string)($order['status'] ?? '')),
            'currency'       => e((string)$currency),
            'subtotal'       => e(ShopAPI::formatMoney((float)($order['subtotal'] ?? 0), (string)$currency)),
            'discount'       => e(ShopAPI::formatMoney((float)($order['discount_total'] ?? 0), (string)$currency)),
            'shipping'       => e(ShopAPI::formatMoney((float)($order['shipping_total'] ?? 0), (string)$currency)),
            'tax'            => e(ShopAPI::formatMoney((float)($order['tax_total'] ?? 0), (string)$currency)),
            'total'          => e(ShopAPI::formatMoney((float)($order['total'] ?? 0), (string)$currency)),
            'coupon_code'    => e((string)($order['coupon_code'] ?? '')),
            'items_html'     => $itemsHtml,  // already HTML — don't escape
            'shipping_addr_html' => $shipAddr, // already HTML — don't escape
            'created_at'     => e((string)($order['created_at'] ?? '')),
        ];
    }

    /**
     * Render the line-items table as HTML. Used as the {items_html}
     * placeholder.
     */
    private function renderItemsHtml(array $order): string {
        $items = $order['items'] ?? [];
        if (empty($items)) return '<p>(No items)</p>';

        $currency = $order['currency'] ?? '';

        $html = '<table cellpadding="6" cellspacing="0" border="0" '
              . 'style="width:100%; border-collapse:collapse; '
              . 'border-bottom:1px solid #ddd; font-family:sans-serif; font-size:14px;">';
        $html .= '<thead><tr style="border-bottom:1px solid #ddd; text-align:left;">';
        $html .= '<th>Item</th>';
        $html .= '<th style="text-align:center; width:60px;">Qty</th>';
        $html .= '<th style="text-align:right; width:100px;">Total</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($items as $i) {
            $name  = e((string)($i['product_name'] ?? ''));
            $sku   = e((string)($i['product_sku'] ?? ''));
            $qty   = (int)($i['quantity'] ?? 0);
            $total = ShopAPI::formatMoney((float)($i['line_total'] ?? 0), (string)$currency);

            $html .= '<tr style="border-bottom:1px solid #eee;">';
            $html .= "<td>{$name}";
            if ($sku !== '' && stripos($sku, 'auto-') !== 0) {
                $html .= "<br><span style=\"color:#888; font-size:12px;\">SKU: {$sku}</span>";
            }
            $html .= '</td>';
            $html .= "<td style=\"text-align:center;\">{$qty}</td>";
            $html .= '<td style="text-align:right;">' . e($total) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    // ──────────────────────────────────────────────────────────
    // Default templates
    // ──────────────────────────────────────────────────────────

    /**
     * Hardcoded default for a given template piece. Admins can
     * override via the settings table (admin/index.php form).
     *
     * Returns an empty string for unknown keys rather than throwing —
     * defensive against a future caller passing a typo.
     */
    public static function defaultTemplate(string $key, string $part): string {
        $defaults = self::allDefaultTemplates();
        return $defaults[$key][$part] ?? '';
    }

    /**
     * Every built-in template, in one structure. Used both by the
     * runtime fallback and by the admin page (to display "this is
     * the current default if you don't override it").
     */
    public static function allDefaultTemplates(): array {
        return [
            'customer_created' => [
                'subject' => 'Your order {order_number} at {site_name}',
                'body'    => self::wrapHtml(<<<HTML
<h2 style="color:#333;">Thanks for your order, {customer_name}!</h2>
<p>We've received your order <strong>{order_number}</strong> and we'll let you know when it ships.</p>
<p>
  <a href="{order_url}" style="background:#222; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; display:inline-block;">
    View your order
  </a>
</p>

<h3 style="margin-top:24px; margin-bottom:8px;">Order details</h3>
{items_html}

<table cellpadding="4" cellspacing="0" border="0" style="width:100%; max-width:400px; margin-top:16px; font-family:sans-serif; font-size:14px;">
  <tr><td>Subtotal</td><td style="text-align:right;">{subtotal}</td></tr>
  <tr><td>Discount</td><td style="text-align:right;">−{discount}</td></tr>
  <tr><td>Shipping</td><td style="text-align:right;">{shipping}</td></tr>
  <tr><td>Tax</td><td style="text-align:right;">{tax}</td></tr>
  <tr style="border-top:1px solid #ddd; font-weight:bold;">
    <td>Total</td><td style="text-align:right;">{total}</td>
  </tr>
</table>

<h3 style="margin-top:24px; margin-bottom:8px;">Shipping to</h3>
<p style="line-height:1.6;">{shipping_addr_html}</p>

<p style="color:#666; font-size:13px; margin-top:30px;">
  Questions? Just reply to this email.
</p>
HTML),
            ],

            'customer_paid' => [
                'subject' => 'Payment confirmed — order {order_number}',
                'body'    => self::wrapHtml(<<<HTML
<h2 style="color:#2a7;">Payment received ✓</h2>
<p>Hi {customer_name},</p>
<p>We've confirmed payment for your order <strong>{order_number}</strong>. We'll start preparing it for shipment shortly.</p>
<p>
  <a href="{order_url}" style="background:#222; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; display:inline-block;">
    View order details
  </a>
</p>
<p style="color:#666; font-size:13px; margin-top:30px;">
  You'll get another email when your order ships.
</p>
HTML),
            ],

            'customer_completed' => [
                'subject' => 'Order {order_number} is complete',
                'body'    => self::wrapHtml(<<<HTML
<h2 style="color:#333;">Your order has been completed</h2>
<p>Hi {customer_name},</p>
<p>Order <strong>{order_number}</strong> has been marked completed on our end. Thanks for shopping with {site_name}!</p>
<p>
  <a href="{order_url}" style="background:#222; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; display:inline-block;">
    Order details
  </a>
</p>
HTML),
            ],

            'admin_created' => [
                'subject' => '[{site_name}] New order: {order_number} — {total}',
                'body'    => self::wrapHtml(<<<HTML
<h2 style="color:#333;">New order: {order_number}</h2>
<p>A new order has been placed. Total: <strong>{total}</strong>.</p>
<p>
  <a href="{admin_order_url}" style="background:#222; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; display:inline-block;">
    Open in admin
  </a>
</p>

<h3 style="margin-top:24px;">Customer</h3>
<p>{customer_name} &lt;{customer_email}&gt;</p>

<h3 style="margin-top:24px;">Items</h3>
{items_html}

<table cellpadding="4" cellspacing="0" border="0" style="width:100%; max-width:400px; margin-top:16px; font-family:sans-serif; font-size:14px;">
  <tr><td>Subtotal</td><td style="text-align:right;">{subtotal}</td></tr>
  <tr><td>Shipping</td><td style="text-align:right;">{shipping}</td></tr>
  <tr><td>Tax</td><td style="text-align:right;">{tax}</td></tr>
  <tr style="border-top:1px solid #ddd; font-weight:bold;">
    <td>Total</td><td style="text-align:right;">{total}</td>
  </tr>
</table>

<h3 style="margin-top:24px;">Ship to</h3>
<p style="line-height:1.6;">{shipping_addr_html}</p>
HTML),
            ],
        ];
    }

    /**
     * Wrap a body fragment in a minimal HTML email skeleton. Email
     * clients are wildly inconsistent — keep the wrapper boring
     * (tables, inline styles, no <style> blocks) and you mostly
     * survive Gmail/Outlook/Apple Mail without surprises.
     *
     * The site_name placeholder in the footer is the only thing the
     * wrapper substitutes; the inner fragment can use any
     * placeholder. We do NOT pre-substitute here — that happens in
     * render().
     */
    private static function wrapHtml(string $bodyFragment): string {
        return <<<HTML
<!doctype html>
<html lang="en">
<body style="margin:0; padding:0; background:#f5f5f5; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
<table cellpadding="0" cellspacing="0" border="0" style="width:100%; background:#f5f5f5;">
<tr><td align="center" style="padding:24px 12px;">
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background:#fff; border-radius:8px;">
  <tr><td style="padding:24px;">
    <div style="font-size:18px; font-weight:600; color:#222; margin-bottom:16px;">
      <a href="{shop_url}" style="color:#222; text-decoration:none;">{site_name}</a>
    </div>
    $bodyFragment
  </td></tr>
  </table>
  <div style="color:#888; font-size:12px; margin-top:16px;">
    <a href="{shop_url}" style="color:#888; text-decoration:none;">{site_name}</a>
  </div>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
