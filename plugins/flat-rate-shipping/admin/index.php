<?php
/**
 * Flat-rate shipping — admin (tier manager).
 *
 * Routes:
 *   GET  ?              → list
 *   GET  ?new           → new tier editor
 *   GET  ?edit=N        → edit tier
 *   POST _action=create|update|delete
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shipping.manage');

$pageTitle  = __('shipping_rates', 'Shipping rates');
$currentNav = 'shipping-flat-rate';

$tid  = current_tenant_id();
$flash = null;
$editing = null;
$mode = 'list';

// The Shop plugin owns the weight unit setting; we just respect it.
$weightUnit = Database::setting('weight_unit') ?: 'oz';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'create' || $action === 'update') {
            // Collect + validate. The "no upper bound" tier is signaled
            // by max_weight being empty in the form, which becomes NULL
            // in the DB. Empty min_weight is interpreted as 0.
            $label = trim((string)($_POST['label'] ?? ''));
            $minW  = ($_POST['min_weight'] ?? '') === ''
                   ? 0.0 : (float)$_POST['min_weight'];
            $maxRaw = trim((string)($_POST['max_weight'] ?? ''));
            $maxW  = $maxRaw === '' ? null : (float)$maxRaw;
            $rate  = (float)($_POST['rate'] ?? 0);
            $order = (int)($_POST['display_order'] ?? 0);
            $active = isset($_POST['active']) ? 1 : 0;

            $err = null;
            if ($minW < 0) $err = __('min_weight_invalid', 'Min weight must be ≥ 0.');
            elseif ($maxW !== null && $maxW <= $minW) {
                $err = __('max_weight_invalid', 'Max weight must be greater than min weight (or leave blank for "no upper bound").');
            }
            elseif ($rate < 0) $err = __('rate_invalid', 'Rate must be ≥ 0.');

            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = [
                    'id' => $id, 'label' => $label,
                    'min_weight' => $minW, 'max_weight' => $maxW,
                    'rate' => $rate, 'display_order' => $order, 'active' => $active,
                ];
            } else {
                $row = [
                    'label'         => $label,
                    'min_weight'    => $minW,
                    'max_weight'    => $maxW,
                    'rate'          => $rate,
                    'display_order' => $order,
                    'active'        => $active,
                ];
                if ($action === 'create') {
                    $newId = Database::insert('flatrateshipping_tiers',
                        array_merge(['tenant_id' => $tid], $row));
                    if (class_exists('AuditLog')) {
                        AuditLog::record('shipping.tier_created', "tier#$newId", ['label' => $label]);
                    }
                    $flash = ['type' => 'success', 'msg' => __('tier_created', 'Shipping tier created.')];
                    header('Location: ?'); exit;
                } else {
                    Database::update('flatrateshipping_tiers', $row,
                        'id = ? AND tenant_id = ?', [$id, $tid]);
                    if (class_exists('AuditLog')) {
                        AuditLog::record('shipping.tier_updated', "tier#$id");
                    }
                    $flash = ['type' => 'success', 'msg' => __('tier_updated', 'Shipping tier updated.')];
                    $mode = 'edit';
                    $editing = Database::row(
                        "SELECT * FROM flatrateshipping_tiers WHERE id = ? AND tenant_id = ?",
                        [$id, $tid]);
                }
            }
        }
        elseif ($action === 'delete' && $id > 0) {
            Database::delete('flatrateshipping_tiers', 'id = ? AND tenant_id = ?', [$id, $tid]);
            if (class_exists('AuditLog')) {
                AuditLog::record('shipping.tier_deleted', "tier#$id");
            }
            $flash = ['type' => 'success', 'msg' => __('tier_deleted', 'Tier deleted.')];
            header('Location: ?'); exit;
        }
    }
}

if ($mode === 'list') {
    if (isset($_GET['new'])) {
        $mode = 'edit';
        $editing = [
            'id' => 0, 'label' => '',
            'min_weight' => 0, 'max_weight' => null,
            'rate' => 0, 'display_order' => 0, 'active' => 1,
        ];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $row = Database::row(
            "SELECT * FROM flatrateshipping_tiers WHERE id = ? AND tenant_id = ?",
            [$id, $tid]);
        if ($row) { $editing = $row; $mode = 'edit'; }
    }
}

require $root . '/admin/partials/header.php';

if (function_exists('slate_breadcrumbs')) {
    slate_breadcrumbs([
        ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
        ['label' => __('shipping_rates', 'Shipping rates')]
            + ($mode === 'edit' ? ['href' => '?'] : []),
    ]);
}
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'edit'):
    $isNew = ((int)($editing['id'] ?? 0)) === 0;
?>
    <div class="page-header">
        <div>
            <h1><?= $isNew ? __('new_tier', 'New shipping tier')
                           : e($editing['label'] ?: __('edit_tier', 'Edit tier')) ?></h1>
            <p class="page-header-sub">
                <?= sprintf(__('weight_unit_note', 'Weights are in %s. Set this in Shop → Settings.'), e($weightUnit)) ?>
            </p>
        </div>
        <a href="?" class="btn btn-ghost">← <?= __('back_to_list', 'Back to list') ?></a>
    </div>

    <div class="card">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <div class="field">
                <label class="field-label" for="label"><?= __('label', 'Label') ?></label>
                <input type="text" id="label" name="label" maxlength="100"
                       value="<?= e($editing['label'] ?? '') ?>"
                       placeholder="<?= e(__('label_placeholder', 'e.g., Standard (under 1 lb)')) ?>">
                <div class="field-hint">
                    <?= __('label_hint', 'Internal label for the admin list. Customers see only the computed rate at checkout.') ?>
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="min_weight">
                        <?= sprintf(__('min_weight_label', 'Min weight (%s)'), e($weightUnit)) ?>
                    </label>
                    <input type="number" id="min_weight" name="min_weight"
                           step="0.001" min="0"
                           value="<?= e((string)($editing['min_weight'] ?? 0)) ?>">
                    <div class="field-hint"><?= __('min_weight_hint', 'Tier applies when cart weight ≥ this value.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="max_weight">
                        <?= sprintf(__('max_weight_label', 'Max weight (%s)'), e($weightUnit)) ?>
                    </label>
                    <input type="number" id="max_weight" name="max_weight"
                           step="0.001" min="0"
                           value="<?= $editing['max_weight'] !== null ? e((string)$editing['max_weight']) : '' ?>"
                           placeholder="<?= e(__('no_upper_bound', 'leave blank for no upper bound')) ?>">
                    <div class="field-hint">
                        <?= __('max_weight_hint', 'Tier applies when cart weight < this value. Leave blank for the overflow tier (no upper limit).') ?>
                    </div>
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="rate">
                        <?= __('rate', 'Rate') ?>
                        (<?= e(Database::setting('shop.currency') ?: 'USD') ?>)
                    </label>
                    <input type="number" id="rate" name="rate"
                           step="0.01" min="0"
                           value="<?= e((string)($editing['rate'] ?? 0)) ?>" required>
                </div>
                <div class="field">
                    <label class="field-label" for="display_order"><?= __('display_order', 'Display order') ?></label>
                    <input type="number" id="display_order" name="display_order"
                           step="1"
                           value="<?= (int)($editing['display_order'] ?? 0) ?>">
                    <div class="field-hint"><?= __('display_order_hint', 'Lower numbers are checked first. Use to break ties when ranges overlap.') ?></div>
                </div>
            </div>

            <div class="field">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="active" value="1"
                           style="width:18px;height:18px;accent-color:var(--accent)"
                           <?= !empty($editing['active']) ? 'checked' : '' ?>>
                    <?= __('active', 'Active') ?>
                </label>
                <div class="field-hint"><?= __('active_hint', 'Inactive tiers are skipped during rate calculation.') ?></div>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $isNew ? __('create', 'Create') : __('save_changes', 'Save changes') ?>
            </button>
        </form>
    </div>

    <?php if (!$isNew): ?>
        <div class="card">
            <div class="card-header"><h2 class="text-danger"><?= __('danger_zone', 'Danger zone') ?></h2></div>
            <form method="post"
                  onsubmit="return confirm(<?= e(json_encode(sprintf(
                      __('confirm_delete_tier', 'Delete tier "%s"?'),
                      $editing['label'] ?: 'this tier'
                  ))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <button type="submit" class="btn btn-danger"><?= __('delete_tier', 'Delete tier') ?></button>
            </form>
        </div>
    <?php endif; ?>

<?php else:
    // ── LIST MODE ──
    $tiers = Database::rows(
        "SELECT * FROM flatrateshipping_tiers WHERE tenant_id = ?
          ORDER BY display_order ASC, min_weight ASC",
        [$tid]);
    $currency = Database::setting('shop.currency') ?: 'USD';
?>
    <div class="page-header">
        <div>
            <h1><?= __('shipping_rates', 'Shipping rates') ?></h1>
            <p class="page-header-sub">
                <?= sprintf(__('shipping_intro',
                    'Weight tiers determine the shipping charge. Weights are in %s. The cart picks the first tier whose range contains the total cart weight.'),
                    e($weightUnit)) ?>
            </p>
        </div>
        <a href="?new" class="btn btn-primary">+ <?= __('new_tier', 'New tier') ?></a>
    </div>

    <?php if (empty($tiers)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_tiers', 'No shipping tiers yet') ?></div>
                <p>
                    <?= __('no_tiers_explain',
                        'Until you add at least one tier, shipping falls back to the legacy flat-rate setting in Shop → Settings.') ?>
                </p>
                <a href="?new" class="btn btn-primary">
                    + <?= __('create_first_tier', 'Create your first tier') ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
            <table class="data-table" style="width:100%; min-width:640px;">
                <thead>
                    <tr>
                        <th><?= __('label', 'Label') ?></th>
                        <th><?= __('weight_range', 'Weight range') ?></th>
                        <th><?= __('rate', 'Rate') ?></th>
                        <th><?= __('order', 'Order') ?></th>
                        <th><?= __('status', 'Status') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tiers as $t):
                    $rangeText = e((string)$t['min_weight']);
                    if ($t['max_weight'] === null) {
                        $rangeText .= '+ ' . e($weightUnit);
                    } else {
                        $rangeText .= ' – ' . e((string)$t['max_weight']) . ' ' . e($weightUnit);
                    }
                ?>
                    <tr>
                        <td><?= e($t['label'] ?: '—') ?></td>
                        <td><?= $rangeText ?></td>
                        <td><?= e($currency) ?> <?= e(number_format((float)$t['rate'], 2)) ?></td>
                        <td><?= (int)$t['display_order'] ?></td>
                        <td>
                            <?php if ($t['active']): ?>
                                <span class="badge badge-active"><?= __('active', 'Active') ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted"><?= __('inactive', 'Inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm">
                                <?= __('edit', 'Edit') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= __('how_it_works', 'How it works') ?></h2></div>
            <ul style="margin:0; padding-left:1.2rem; line-height:1.6;">
                <li><?= __('how_1', 'Cart total weight is summed across all line items (product weight × quantity).') ?></li>
                <li><?= __('how_2', 'The first tier whose min ≤ weight < max wins (or min ≤ weight if max is blank).') ?></li>
                <li><?= __('how_3', 'Display order breaks ties when ranges overlap; lower numbers go first.') ?></li>
                <li><?= __('how_4', 'Free-shipping threshold in Shop → Settings always overrides per-tier rates.') ?></li>
                <li><?= __('how_5', 'If no tier matches (e.g., a heavy cart with no overflow tier), shipping falls back to the legacy flat rate in Shop settings.') ?></li>
            </ul>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
