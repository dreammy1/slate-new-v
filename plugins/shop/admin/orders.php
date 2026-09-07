<?php
/**
 * Shop — Orders admin page.
 * List, view detail, update status, create manual order.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.view_orders');

$pageTitle  = __('shop_orders', 'Orders');
$currentNav = 'shop-orders';

$flash    = null;
$tid      = current_tenant_id();
$view     = (int)($_GET['view'] ?? 0);  // view single order
$action   = $_GET['action'] ?? 'list';
$currency = Database::setting('shop.currency') ?: 'USD';

// ──────────────────────────────────────────────────────────
// POST handlers
// ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requirePerm('shop.manage_orders');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $postAction = $_POST['_action'] ?? '';

        if ($postAction === 'update_status') {
            $oid    = (int)($_POST['order_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (ShopAPI::updateStatus($oid, $status)) {
                AuditLog::record('shop.order_status', "order#$oid", ['status' => $status]);
                $flash = ['type' => 'success', 'msg' => 'Status updated.'];
                $view  = $oid;
            } else {
                $flash = ['type' => 'error', 'msg' => 'Invalid status.'];
            }
        } elseif ($postAction === 'update_notes') {
            $oid   = (int)($_POST['order_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            Database::update('shop_orders', ['notes' => $notes ?: null],
                'id = ? AND tenant_id = ?', [$oid, $tid]);
            $flash = ['type' => 'success', 'msg' => 'Notes saved.'];
            $view  = $oid;
        } elseif ($postAction === 'create_order') {
            // Manual order creation
            $email = trim((string)($_POST['customer_email'] ?? ''));
            $name  = trim((string)($_POST['customer_name'] ?? ''));
            $items = [];
            $pids  = $_POST['product_id'] ?? [];
            $qtys  = $_POST['qty'] ?? [];
            foreach ($pids as $i => $pid) {
                $pid = (int)$pid;
                $qty = max(1, (int)($qtys[$i] ?? 1));
                if ($pid > 0) $items[] = ['product_id' => $pid, 'qty' => $qty];
            }
            if ($email === '' || empty($items)) {
                $flash = ['type' => 'error', 'msg' => 'Customer email and at least one product are required.'];
                $action = 'new';
            } else {
                try {
                    $newId = ShopAPI::createOrder($email, $name, $items, [
                        'payment_method' => $_POST['payment_method'] ?? 'manual',
                        'notes'          => $_POST['notes'] ?? '',
                        'coupon_code'    => $_POST['coupon_code'] ?? '',
                        'status'         => 'processing',
                    ]);
                    AuditLog::record('shop.order_created', "order#$newId", ['email' => $email]);
                    $flash  = ['type' => 'success', 'msg' => "Order #$newId created."];
                    $view   = $newId;
                    $action = 'list';
                } catch (\Throwable $e) {
                    $flash  = ['type' => 'error', 'msg' => 'Could not create order: ' . $e->getMessage()];
                    $action = 'new';
                }
            }
        }
    }
}

// Filters
$filterStatus = $_GET['status'] ?? '';
$search       = trim((string)($_GET['q'] ?? ''));
$args         = [$tid];
$where        = 'tenant_id = ?';
if ($filterStatus !== '') { $where .= ' AND status = ?'; $args[] = $filterStatus; }
if ($search !== '') {
    $where .= ' AND (order_number LIKE ? OR customer_email LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $search . '%';
    $args[] = $like; $args[] = $like; $args[] = $like;
}
// Pagination
$perPage    = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalOrders = (int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE {$where}", $args);
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$orders = Database::rows(
    "SELECT * FROM shop_orders WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}", $args
);

// Single order view
$orderDetail = null;
if ($view > 0) {
    $orderDetail = ShopAPI::order($view);
}

// Active products for new-order form
$activeProducts = Database::rows(
    "SELECT id, name, sku, price, sale_price, stock_qty, manage_stock
       FROM shop_products WHERE tenant_id = ? AND status = 'active' ORDER BY name",
    [$tid]
);

require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Orders'],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($orderDetail): ?>
<!-- ══════════════════════════ ORDER DETAIL ══════════════════════════ -->
<div class="page-header">
    <div>
        <h1>Order <?= e($orderDetail['order_number']) ?></h1>
        <span class="badge <?= ShopAPI::statusBadgeClass($orderDetail['status']) ?>">
            <?= e($orderDetail['status']) ?>
        </span>
    </div>
    <a href="?" class="btn btn-ghost">← Back to Orders</a>
</div>

<div class="flex gap-2" style="flex-wrap:wrap; align-items:flex-start;">

    <div style="flex:2; min-width:280px;">
        <!-- Line items -->
        <div class="card">
            <div class="card-header"><h2>Items</h2></div>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left; padding:0.4rem 0.5rem; font-weight:600;">Product</th>
                        <th style="text-align:right; padding:0.4rem 0.5rem; font-weight:600;">Qty</th>
                        <th style="text-align:right; padding:0.4rem 0.5rem; font-weight:600;">Unit</th>
                        <th style="text-align:right; padding:0.4rem 0.5rem; font-weight:600;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderDetail['items'] as $li): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:0.4rem 0.5rem;">
                            <?= e($li['product_name']) ?>
                            <?php if ($li['product_sku']): ?>
                                <div class="text-muted text-sm">SKU: <?= e($li['product_sku']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; padding:0.4rem 0.5rem;"><?= (int)$li['quantity'] ?></td>
                        <td style="text-align:right; padding:0.4rem 0.5rem;"><?= e(ShopAPI::formatMoney((float)$li['unit_price'], $orderDetail['currency'])) ?></td>
                        <td style="text-align:right; padding:0.4rem 0.5rem;"><?= e(ShopAPI::formatMoney((float)$li['line_total'], $orderDetail['currency'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="3" style="text-align:right; padding:0.3rem 0.5rem;" class="text-muted">Subtotal</td>
                        <td style="text-align:right; padding:0.3rem 0.5rem;"><?= e(ShopAPI::formatMoney((float)$orderDetail['subtotal'], $orderDetail['currency'])) ?></td></tr>
                    <?php if ((float)$orderDetail['discount_total'] > 0): ?>
                    <tr><td colspan="3" style="text-align:right; padding:0.3rem 0.5rem;" class="text-muted">
                        Discount <?= $orderDetail['coupon_code'] ? '(' . e($orderDetail['coupon_code']) . ')' : '' ?></td>
                        <td style="text-align:right; padding:0.3rem 0.5rem;" class="text-danger">
                            −<?= e(ShopAPI::formatMoney((float)$orderDetail['discount_total'], $orderDetail['currency'])) ?></td></tr>
                    <?php endif; ?>
                    <tr><td colspan="3" style="text-align:right; padding:0.3rem 0.5rem;" class="text-muted">Shipping</td>
                        <td style="text-align:right; padding:0.3rem 0.5rem;"><?= e(ShopAPI::formatMoney((float)$orderDetail['shipping_total'], $orderDetail['currency'])) ?></td></tr>
                    <?php if ((float)$orderDetail['tax_total'] > 0): ?>
                    <tr><td colspan="3" style="text-align:right; padding:0.3rem 0.5rem;" class="text-muted">Tax</td>
                        <td style="text-align:right; padding:0.3rem 0.5rem;"><?= e(ShopAPI::formatMoney((float)$orderDetail['tax_total'], $orderDetail['currency'])) ?></td></tr>
                    <?php endif; ?>
                    <tr style="border-top:2px solid var(--border);">
                        <td colspan="3" style="text-align:right; padding:0.5rem; font-weight:700;">Total</td>
                        <td style="text-align:right; padding:0.5rem; font-weight:700; font-size:1.1rem;">
                            <?= e(ShopAPI::formatMoney((float)$orderDetail['total'], $orderDetail['currency'])) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($orderDetail['ship_address_1']): ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2>Shipping Address</h2></div>
            <p style="line-height:1.7; margin:0;">
                <?= e($orderDetail['ship_address_1']) ?><br>
                <?php if ($orderDetail['ship_address_2']): ?><?= e($orderDetail['ship_address_2']) ?><br><?php endif; ?>
                <?= e($orderDetail['ship_city']) ?>, <?= e($orderDetail['ship_state']) ?> <?= e($orderDetail['ship_postcode']) ?><br>
                <?= e($orderDetail['ship_country']) ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <div style="flex:1; min-width:220px;">
        <!-- Status update -->
        <?php if (Auth::can('shop.manage_orders')): ?>
        <div class="card">
            <div class="card-header"><h2>Update Status</h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update_status">
                <input type="hidden" name="order_id" value="<?= (int)$orderDetail['id'] ?>">
                <div class="field">
                    <select name="status">
                        <?php foreach (['pending','processing','on-hold','completed','cancelled','refunded'] as $s): ?>
                            <option value="<?= $s ?>" <?= $orderDetail['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Update Status</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Customer info -->
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2>Customer</h2></div>
            <div><strong><?= e($orderDetail['customer_name']) ?></strong></div>
            <div class="text-muted"><?= e($orderDetail['customer_email']) ?></div>
            <div class="text-muted text-sm mt-3">Payment: <?= e($orderDetail['payment_method'] ?: '—') ?></div>
            <div class="text-muted text-sm">Placed: <?= e($orderDetail['created_at']) ?></div>
        </div>

        <!-- Notes -->
        <?php if (Auth::can('shop.manage_orders')): ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2>Notes</h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update_notes">
                <input type="hidden" name="order_id" value="<?= (int)$orderDetail['id'] ?>">
                <div class="field">
                    <textarea name="notes" rows="3"><?= e($orderDetail['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-sm">Save Notes</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'new' && Auth::can('shop.manage_orders')): ?>
<!-- ══════════════════════════ NEW ORDER FORM ══════════════════════════ -->
<div class="page-header">
    <div><h1>New Manual Order</h1></div>
    <a href="?" class="btn btn-ghost">← Back</a>
</div>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="create_order">
    <div class="split-layout">
        <div>
            <div class="card">
                <div class="card-header"><h2>Customer</h2></div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Email <span class="field-required">*</span></label>
                        <input type="email" name="customer_email" required>
                    </div>
                    <div class="field">
                        <label class="field-label">Full Name</label>
                        <input type="text" name="customer_name">
                    </div>
                </div>
            </div>
            <div class="card" style="margin-top:1rem;">
                <div class="card-header"><h2>Products</h2></div>
                <div id="order-items">
                    <div class="order-item-row">
                        <div class="field" style="margin:0;">
                            <label class="field-label">Product</label>
                            <select name="product_id[]">
                                <option value="">— Select product —</option>
                                <?php foreach ($activeProducts as $ap): ?>
                                    <option value="<?= (int)$ap['id'] ?>">
                                        <?= e($ap['name']) ?> — <?= e(ShopAPI::formatMoney(ShopAPI::effectivePrice($ap))) ?>
                                        <?= $ap['manage_stock'] ? '(' . (int)$ap['stock_qty'] . ' in stock)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label">Qty</label>
                            <input type="number" name="qty[]" value="1" min="1">
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" id="add-item-btn"
                        style="margin-top:0.5rem;">+ Add another product</button>
            </div>
        </div>
        <div>
            <div class="card">
                <div class="card-header"><h2>Options</h2></div>
                <div class="field">
                    <label class="field-label">Payment Method</label>
                    <select name="payment_method">
                        <option value="manual">Manual / Offline</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label">Coupon Code</label>
                    <input type="text" name="coupon_code" placeholder="Optional">
                </div>
                <div class="field">
                    <label class="field-label">Internal Notes</label>
                    <textarea name="notes" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Create Order</button>
            </div>
        </div>
    </div>
</form>
<style>
/* Each product+qty line inside the Products card.
   - ≤480px: stack (product field on top, qty below)
   - >480px: product takes most of the row, qty fixed at 90px */
.order-item-row {
    display: grid;
    gap: 0.5rem;
    grid-template-columns: 1fr;
    margin-bottom: 0.6rem;
}
@media (min-width: 480px) {
    .order-item-row { grid-template-columns: 1fr 90px; align-items: end; }
}
</style>
<script>
document.getElementById('add-item-btn').addEventListener('click', function () {
    var container = document.getElementById('order-items');
    var first = container.querySelector('.order-item-row');
    var clone = first.cloneNode(true);
    clone.querySelector('select').value = '';
    clone.querySelector('input[type="number"]').value = '1';
    container.appendChild(clone);
});
</script>

<?php else: ?>
<!-- ══════════════════════════ ORDERS LIST ══════════════════════════ -->
<div class="page-header">
    <div>
        <h1>Orders</h1>
        <p class="page-header-sub"><?= number_format($totalOrders) ?> order(s)</p>
    </div>
    <?php if (Auth::can('shop.manage_orders')): ?>
    <a href="?action=new" class="btn btn-primary">+ New Order</a>
    <?php endif; ?>
</div>

<div class="flex gap-2 mb-2" style="flex-wrap:wrap;">
    <form method="get" style="display:flex; gap:0.5rem; flex:1;">
        <input type="text" name="q" placeholder="Search email, name, order #…"
               value="<?= e($search) ?>" style="flex:1;">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending','processing','on-hold','completed','cancelled','refunded'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
        <?php if ($search || $filterStatus): ?>
            <a href="?" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($orders)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No orders found</div>
            <p>Orders placed by customers will appear here.</p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($orders as $o):
            ob_start(); ?>
            <a href="?view=<?= (int)$o['id'] ?>" class="btn btn-sm">View</a>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($o['customer_name'] ?: $o['customer_email'], 0, 2)),
                'avatar_color' => ShopAPI::statusBadgeClass($o['status']),
                'title'        => $o['order_number'] . ' — ' . ($o['customer_name'] ?: $o['customer_email']),
                'badge'        => [$o['status'], str_replace('badge-', '', ShopAPI::statusBadgeClass($o['status']))],
                'meta'         => ShopAPI::formatMoney((float)$o['total'], $o['currency'])
                    . ' · ' . $o['created_at'],
                'detail' => [
                    'Order #'  => $o['order_number'],
                    'Customer' => $o['customer_email'],
                    'Total'    => ShopAPI::formatMoney((float)$o['total'], $o['currency']),
                    'Status'   => $o['status'],
                    'Date'     => $o['created_at'],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalOrders, 'per_page' => $perPage, 'label' => 'orders',
    ]); ?>
<?php endif; ?>

<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
