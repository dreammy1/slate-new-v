<?php
/**
 * Shop — Coupons admin page.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.manage_coupons');

$pageTitle  = __('shop_coupons', 'Coupons');
$currentNav = 'shop-coupons';

$flash  = null;
$tid    = current_tenant_id();
$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ──────────────────────────────────────────────────────────
// POST handlers
// ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $postAction = $_POST['_action'] ?? '';

        if (in_array($postAction, ['create', 'update'], true)) {
            $code     = strtoupper(trim((string)($_POST['code'] ?? '')));
            $type     = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
            $value    = max(0, (float)($_POST['value'] ?? 0));
            $minOrder = trim((string)($_POST['min_order'] ?? '')) !== '' ? (float)$_POST['min_order'] : null;
            $maxUses  = trim((string)($_POST['max_uses'] ?? '')) !== '' ? (int)$_POST['max_uses'] : null;
            $expires  = trim((string)($_POST['expires_at'] ?? '')) !== '' ? $_POST['expires_at'] . ' 23:59:59' : null;
            $active   = isset($_POST['active']) ? 1 : 0;

            if ($code === '') {
                $flash = ['type' => 'error', 'msg' => 'Coupon code is required.'];
            } elseif ($value <= 0) {
                $flash = ['type' => 'error', 'msg' => 'Discount value must be greater than 0.'];
            } else {
                $data = [
                    'code'      => mb_substr($code, 0, 100),
                    'type'      => $type,
                    'value'     => $value,
                    'min_order' => $minOrder,
                    'max_uses'  => $maxUses,
                    'expires_at'=> $expires,
                    'active'    => $active,
                ];
                if ($postAction === 'create') {
                    $data['tenant_id'] = $tid;
                    try {
                        $newId = Database::insert('shop_coupons', $data);
                        AuditLog::record('shop.coupon_created', "coupon#$newId", ['code' => $code]);
                        $flash = ['type' => 'success', 'msg' => "Coupon {$code} created."];
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'That coupon code already exists.'];
                    }
                    $action = 'list';
                } else {
                    $cid = (int)($_POST['coupon_id'] ?? 0);
                    Database::update('shop_coupons', $data, 'id = ? AND tenant_id = ?', [$cid, $tid]);
                    AuditLog::record('shop.coupon_updated', "coupon#$cid", ['code' => $code]);
                    $flash  = ['type' => 'success', 'msg' => 'Coupon updated.'];
                    $action = 'list';
                }
            }
        } elseif ($postAction === 'delete') {
            $cid = (int)($_POST['coupon_id'] ?? 0);
            Database::delete('shop_coupons', 'id = ? AND tenant_id = ?', [$cid, $tid]);
            AuditLog::record('shop.coupon_deleted', "coupon#$cid");
            $flash  = ['type' => 'success', 'msg' => 'Coupon deleted.'];
            $action = 'list';
        }
    }
}

$editing = null;
if ($action === 'edit' && $editId > 0) {
    $editing = Database::row(
        "SELECT * FROM shop_coupons WHERE id = ? AND tenant_id = ?", [$editId, $tid]
    );
    if (!$editing) $action = 'list';
}

$coupons = Database::rows(
    "SELECT * FROM shop_coupons WHERE tenant_id = ? ORDER BY created_at DESC", [$tid]
);

require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Coupons'],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || ($action === 'edit' && $editing)): ?>
<!-- ══════════════════════════ COUPON FORM ══════════════════════════ -->
<div class="page-header">
    <h1><?= $editing ? 'Edit Coupon' : 'New Coupon' ?></h1>
    <a href="?" class="btn btn-ghost">← Back</a>
</div>
<div class="card" style="max-width:540px;">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="coupon_id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>

        <div class="field">
            <label class="field-label">Code <span class="field-required">*</span></label>
            <input type="text" name="code" required maxlength="100" style="text-transform:uppercase;"
                   value="<?= e($editing['code'] ?? '') ?>" placeholder="e.g. SUMMER20">
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label">Discount Type</label>
                <select name="type">
                    <option value="percent" <?= ($editing['type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                    <option value="fixed"   <?= ($editing['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option>
                </select>
            </div>
            <div class="field">
                <label class="field-label">Value <span class="field-required">*</span></label>
                <input type="number" name="value" min="0.01" step="0.01" required
                       value="<?= e($editing['value'] ?? '') ?>" placeholder="20">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label">Minimum Order</label>
                <input type="number" name="min_order" min="0" step="0.01"
                       value="<?= e($editing['min_order'] ?? '') ?>" placeholder="Optional">
            </div>
            <div class="field">
                <label class="field-label">Max Uses</label>
                <input type="number" name="max_uses" min="1" step="1"
                       value="<?= e($editing['max_uses'] ?? '') ?>" placeholder="Unlimited">
            </div>
        </div>
        <div class="field">
            <label class="field-label">Expires</label>
            <input type="date" name="expires_at"
                   value="<?= e(!empty($editing['expires_at']) ? substr($editing['expires_at'], 0, 10) : '') ?>">
        </div>
        <div class="field">
            <label style="display:flex; gap:0.5rem; align-items:center; cursor:pointer;">
                <input type="checkbox" name="active" value="1"
                       <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                Active (usable at checkout)
            </label>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Coupon' ?></button>
            <a href="?" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- ══════════════════════════ COUPONS LIST ══════════════════════════ -->
<div class="page-header">
    <h1>Coupons</h1>
    <a href="?action=new" class="btn btn-primary">+ New Coupon</a>
</div>

<?php if (empty($coupons)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No coupons yet</div>
            <p><a href="?action=new" class="btn btn-primary">Create your first coupon</a></p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($coupons as $c):
            $expired = $c['expires_at'] && strtotime($c['expires_at']) < time();
            $full    = $c['max_uses'] && $c['uses'] >= $c['max_uses'];
            $statusLabel = !$c['active'] ? 'inactive' : ($expired ? 'expired' : ($full ? 'used up' : 'active'));
            $statusClass = $statusLabel === 'active' ? 'badge-active' : 'badge-inactive';
            ob_start(); ?>
            <a href="?action=edit&id=<?= (int)$c['id'] ?>" class="btn btn-sm">Edit</a>
            <form method="post" style="margin:0;"
                  onsubmit="return confirm('Delete coupon <?= e(addslashes($c['code'])) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </form>
            <?php $actions = ob_get_clean();
            $discountLabel = $c['type'] === 'percent'
                ? (float)$c['value'] . '% off'
                : ShopAPI::formatMoney((float)$c['value']) . ' off';
            slate_data_row([
                'avatar'       => 'CO',
                'avatar_color' => $statusLabel === 'active' ? 'success' : 'muted',
                'title'        => $c['code'],
                'badge'        => [$statusLabel, str_replace('badge-', '', $statusClass)],
                'meta'         => $discountLabel
                    . ($c['min_order'] ? ' · min order ' . ShopAPI::formatMoney((float)$c['min_order']) : '')
                    . ' · ' . (int)$c['uses'] . ' use(s)'
                    . ($c['max_uses'] ? ' of ' . (int)$c['max_uses'] : ''),
                'detail' => [
                    'Code'      => $c['code'],
                    'Type'      => ucfirst($c['type']),
                    'Value'     => $discountLabel,
                    'Min Order' => $c['min_order'] ? ShopAPI::formatMoney((float)$c['min_order']) : '—',
                    'Uses'      => (int)$c['uses'] . ($c['max_uses'] ? ' / ' . (int)$c['max_uses'] : ''),
                    'Expires'   => $c['expires_at'] ? substr($c['expires_at'], 0, 10) : 'Never',
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>
<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
