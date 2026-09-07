<?php
/**
 * Shop — Settings admin page.
 * Currency, tax, shipping, order prefix.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.manage_settings');

$pageTitle  = 'Shop Settings';
$currentNav = 'shop';

$flash = null;
$tid   = current_tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $fields = [
            'shop.currency'                 => mb_substr(strtoupper(trim((string)($_POST['currency'] ?? 'USD'))), 0, 3),
            'shop.tax_rate'                 => max(0, (float)($_POST['tax_rate'] ?? 0)),
            'shop.flat_shipping_rate'       => max(0, (float)($_POST['flat_shipping_rate'] ?? 0)),
            'shop.free_shipping_threshold'  => max(0, (float)($_POST['free_shipping_threshold'] ?? 0)),
            'shop.order_prefix'             => mb_substr(strtoupper(trim((string)($_POST['order_prefix'] ?? 'ORD'))), 0, 10),
        ];
        foreach ($fields as $key => $val) {
            Database::setSetting($key, (string)$val);
        }
        AuditLog::record('shop.settings_changed');
        $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
    }
}

$currency            = Database::setting('shop.currency') ?: 'USD';
$taxRate             = Database::setting('shop.tax_rate') ?: '0';
$flatShipping        = Database::setting('shop.flat_shipping_rate') ?: '0';
$freeThreshold       = Database::setting('shop.free_shipping_threshold') ?: '0';
$orderPrefix         = Database::setting('shop.order_prefix') ?: 'ORD';

require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Settings'],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Shop Settings</h1>
</div>

<form method="post" style="max-width:540px;">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2>General</h2></div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="currency">Currency Code</label>
                <input type="text" id="currency" name="currency" maxlength="3" style="text-transform:uppercase;"
                       value="<?= e($currency) ?>" placeholder="USD">
                <div class="field-hint">3-letter ISO code (USD, EUR, GBP, BDT…)</div>
            </div>
            <div class="field">
                <label class="field-label" for="order_prefix">Order Number Prefix</label>
                <input type="text" id="order_prefix" name="order_prefix" maxlength="10"
                       value="<?= e($orderPrefix) ?>" placeholder="ORD">
                <div class="field-hint">e.g. ORD-00001</div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h2>Tax</h2></div>
        <div class="field">
            <label class="field-label" for="tax_rate">Tax Rate (%)</label>
            <input type="number" id="tax_rate" name="tax_rate" min="0" max="100" step="0.01"
                   value="<?= e($taxRate) ?>" placeholder="0">
            <div class="field-hint">Applied to (subtotal − discount + shipping). Set to 0 to disable.</div>
        </div>
    </div>

    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h2>Shipping</h2></div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="flat_shipping_rate">Flat Rate</label>
                <input type="number" id="flat_shipping_rate" name="flat_shipping_rate" min="0" step="0.01"
                       value="<?= e($flatShipping) ?>" placeholder="0">
                <div class="field-hint">Added to every order. 0 = free shipping.</div>
            </div>
            <div class="field">
                <label class="field-label" for="free_shipping_threshold">Free Shipping Threshold</label>
                <input type="number" id="free_shipping_threshold" name="free_shipping_threshold" min="0" step="0.01"
                       value="<?= e($freeThreshold) ?>" placeholder="0">
                <div class="field-hint">Orders ≥ this amount get free shipping. 0 = disabled.</div>
            </div>
        </div>
    </div>

    <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <a href="<?= e(plugin_url('shop', 'admin/index.php')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<?php require $root . '/admin/partials/footer.php'; ?>
