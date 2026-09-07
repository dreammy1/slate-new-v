<?php
/**
 * Shop — Customers admin page.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.view_orders');

$pageTitle  = __('shop_customers', 'Customers');
$currentNav = 'shop-customers';

$flash    = null;
$tid      = current_tenant_id();
$viewId   = (int)($_GET['view'] ?? 0);
$search   = trim((string)($_GET['q'] ?? ''));

// POST: edit customer notes / details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requirePerm('shop.manage_orders');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        Database::update('shop_customers', ['notes' => $notes ?: null],
            'id = ? AND tenant_id = ?', [$cid, $tid]);
        $flash  = ['type' => 'success', 'msg' => 'Customer updated.'];
        $viewId = $cid;
    }
}

// Build list
$args  = [$tid];
$where = 'tenant_id = ?';
if ($search !== '') {
    $where .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $like = '%' . $search . '%';
    $args[] = $like; $args[] = $like; $args[] = $like;
}
$customers = Database::rows(
    "SELECT c.*,
        (SELECT COUNT(*) FROM shop_orders o WHERE o.customer_id = c.id AND o.tenant_id = c.tenant_id) AS order_count,
        (SELECT COALESCE(SUM(o.total),0) FROM shop_orders o WHERE o.customer_id = c.id AND o.tenant_id = c.tenant_id AND o.status IN ('processing','completed')) AS lifetime_value
       FROM shop_customers c
      WHERE {$where}
      ORDER BY c.created_at DESC
      LIMIT 200",
    $args
);

// Single customer detail
$customer     = null;
$customerOrders = [];
if ($viewId > 0) {
    $customer = Database::row(
        "SELECT * FROM shop_customers WHERE id = ? AND tenant_id = ?", [$viewId, $tid]
    );
    if ($customer) {
        $customerOrders = Database::rows(
            "SELECT id, order_number, status, total, currency, created_at
               FROM shop_orders WHERE customer_id = ? AND tenant_id = ?
               ORDER BY created_at DESC LIMIT 50",
            [$viewId, $tid]
        );
    }
}

$currency = Database::setting('shop.currency') ?: 'USD';
require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Customers'],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($customer): ?>
<!-- ══════════════════════════ CUSTOMER DETAIL ══════════════════════════ -->
<div class="page-header">
    <div>
        <h1><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?></h1>
        <p class="page-header-sub"><?= e($customer['email']) ?></p>
    </div>
    <a href="?" class="btn btn-ghost">← Back to Customers</a>
</div>

<div class="split-layout">
    <div>
        <div class="card">
            <div class="card-header"><h2>Order History</h2></div>
            <?php if (empty($customerOrders)): ?>
                <p class="text-muted text-sm">No orders yet.</p>
            <?php else: ?>
                <div class="data-list" data-single-open>
                    <?php foreach ($customerOrders as $o):
                        ob_start(); ?>
                        <a href="<?= e(plugin_url('shop', 'admin/orders.php')) ?>?view=<?= (int)$o['id'] ?>"
                           class="btn btn-sm">View</a>
                        <?php $actions = ob_get_clean();
                        slate_data_row([
                            'avatar'       => mb_strtoupper(mb_substr($o['order_number'], -2)),
                            'avatar_color' => ShopAPI::statusBadgeClass($o['status']),
                            'title'        => $o['order_number'],
                            'badge'        => [$o['status'], str_replace('badge-', '', ShopAPI::statusBadgeClass($o['status']))],
                            'meta'         => ShopAPI::formatMoney((float)$o['total'], $o['currency'])
                                . ' · ' . $o['created_at'],
                            'actions'      => $actions,
                        ]);
                    endforeach; ?>
                </div>
                <?php slate_data_list_script(); ?>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <div class="card">
            <div class="card-header"><h2>Details</h2></div>
            <div class="text-muted text-sm">Email</div>
            <div class="mb-2"><?= e($customer['email']) ?></div>
            <?php if ($customer['phone']): ?>
            <div class="text-muted text-sm">Phone</div>
            <div class="mb-2"><?= e($customer['phone']) ?></div>
            <?php endif; ?>
            <div class="text-muted text-sm">Customer since</div>
            <div><?= e($customer['created_at']) ?></div>
        </div>
        <?php if (Auth::can('shop.manage_orders')): ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2>Notes</h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                <div class="field">
                    <textarea name="notes" rows="4"><?= e($customer['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-sm">Save Notes</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════ CUSTOMERS LIST ══════════════════════════ -->
<div class="page-header">
    <div>
        <h1>Customers</h1>
        <p class="page-header-sub"><?= count($customers) ?> customer(s)</p>
    </div>
</div>
<div class="flex gap-2 mb-2">
    <form method="get" style="display:flex; gap:0.5rem; flex:1;">
        <input type="text" name="q" placeholder="Search email or name…"
               value="<?= e($search) ?>" style="flex:1;">
        <button type="submit" class="btn">Search</button>
        <?php if ($search): ?><a href="?" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
</div>

<?php if (empty($customers)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No customers yet</div>
            <p>Customers are created automatically when orders are placed.</p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($customers as $c):
            $fullName = trim($c['first_name'] . ' ' . $c['last_name']);
            ob_start(); ?>
            <a href="?view=<?= (int)$c['id'] ?>" class="btn btn-sm">View</a>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($fullName ?: $c['email'], 0, 2)),
                'avatar_color' => 'accent',
                'title'        => $fullName ?: $c['email'],
                'meta'         => $c['email']
                    . ' · ' . (int)$c['order_count'] . ' orders'
                    . ' · LTV: ' . ShopAPI::formatMoney((float)$c['lifetime_value'], $currency),
                'detail' => [
                    'Email'        => $c['email'],
                    'Orders'       => $c['order_count'],
                    'Lifetime Val' => ShopAPI::formatMoney((float)$c['lifetime_value'], $currency),
                    'Since'        => $c['created_at'],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>
<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
