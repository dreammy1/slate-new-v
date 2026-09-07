<?php
/**
 * Box presets management — list, create, edit, delete, reorder.
 *
 * Each row in the list is editable inline; "Add new" appends a new
 * row at the bottom. We keep this on a single page rather than
 * separate create/edit forms because there are usually only 4-8 box
 * presets total, and inline editing is faster for the admin.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shipping_flat_rate.manage');

require_once dirname(__DIR__) . '/ShippingAPI.php';

$pageTitle  = 'Shipping boxes';
$currentNav = 'shop-shipping-boxes';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } elseif (($_POST['_action'] ?? '') === 'save_all') {
        // We receive the rows as parallel arrays indexed by an
        // existing-row id OR a new-row marker like 'new_0'. We do all
        // inserts/updates in a single transaction so a malformed row
        // doesn't leave the table half-saved.
        $ids        = $_POST['box_id']        ?? [];
        $slugs      = $_POST['box_slug']      ?? [];
        $names      = $_POST['box_name']      ?? [];
        $prices     = $_POST['box_price']     ?? [];
        $capacities = $_POST['box_capacity']  ?? [];
        $sortOrders = $_POST['box_sort_order'] ?? [];
        $actives    = $_POST['box_active']    ?? [];
        $notes      = $_POST['box_notes']     ?? [];
        $deletes    = $_POST['box_delete']    ?? [];

        $errors  = [];
        $updates = 0;
        $inserts = 0;
        $removes = 0;
        $now = slate_db_now();

        $tid = current_tenant_id();
        $pdo = Database::get();
        try {
            $pdo->beginTransaction();

            // Process deletes first so freed-up slugs can be reused.
            foreach ($deletes as $delId) {
                $delId = (int)$delId;
                if ($delId > 0) {
                    Database::query(
                        "DELETE FROM shippingflatrate_boxes WHERE id = ? AND tenant_id = ?",
                        [$delId, $tid]);
                    $removes++;
                }
            }

            // Process saves
            $rowKeys = array_keys($names);
            foreach ($rowKeys as $rk) {
                // Skip rows that are being deleted (we just processed them above)
                if (!empty($deletes) && in_array((int)($ids[$rk] ?? 0), array_map('intval', $deletes), true)) {
                    continue;
                }

                $slug    = trim((string)($slugs[$rk] ?? ''));
                $name    = trim((string)($names[$rk] ?? ''));
                $price   = (float)($prices[$rk] ?? 0);
                $cap     = max(1, (int)($capacities[$rk] ?? 1));
                $sort    = (int)($sortOrders[$rk] ?? 100);
                $active  = isset($actives[$rk]) ? 1 : 0;
                $note    = trim((string)($notes[$rk] ?? ''));
                $existId = (int)($ids[$rk] ?? 0);

                // Skip totally-empty new rows
                if ($existId === 0 && $slug === '' && $name === '') continue;

                // Validate slug format (used as PHP-safe identifier
                // and routed through HTML form names)
                if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $slug)) {
                    $errors[] = "Row '$name': slug '$slug' invalid (lowercase, digits, dash, underscore; 2–64 chars)";
                    continue;
                }
                if ($name === '') {
                    $errors[] = "Row '$slug': name is required";
                    continue;
                }
                if ($price < 0) {
                    $errors[] = "Row '$name': price must be ≥ 0";
                    continue;
                }

                if ($existId > 0) {
                    Database::update('shippingflatrate_boxes', [
                        'slug'       => $slug,
                        'name'       => $name,
                        'price'      => $price,
                        'capacity'   => $cap,
                        'sort_order' => $sort,
                        'is_active'  => $active,
                        'notes'      => $note ?: null,
                        'updated_at' => $now,
                    ], 'id = ? AND tenant_id = ?', [$existId, $tid]);
                    $updates++;
                } else {
                    // Check for slug collision before insert (within this tenant)
                    $exists = (int)Database::value(
                        "SELECT COUNT(*) FROM shippingflatrate_boxes WHERE slug = ? AND tenant_id = ?",
                        [$slug, $tid]
                    );
                    if ($exists > 0) {
                        $errors[] = "Row '$name': slug '$slug' already exists";
                        continue;
                    }
                    Database::insert('shippingflatrate_boxes', [
                        'tenant_id'  => $tid,
                        'slug'       => $slug,
                        'name'       => $name,
                        'price'      => $price,
                        'capacity'   => $cap,
                        'sort_order' => $sort,
                        'is_active'  => $active,
                        'notes'      => $note ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserts++;
                }
            }

            if ($errors) {
                $pdo->rollBack();
                $flash = ['type' => 'error',
                    'msg' => 'Save aborted: ' . implode(' · ', $errors)];
            } else {
                $pdo->commit();
                $parts = [];
                if ($inserts) $parts[] = "$inserts added";
                if ($updates) $parts[] = "$updates updated";
                if ($removes) $parts[] = "$removes removed";
                $msg = $parts ? ('Boxes saved (' . implode(', ', $parts) . ').')
                              : 'No changes.';
                $flash = ['type' => 'success', 'msg' => $msg];
            }
        } catch (\Throwable $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
            $flash = ['type' => 'error', 'msg' => 'Database error: ' . $e->getMessage()];
        }
    }
}

$boxes = ShippingAPI::allBoxes();

require $root . '/admin/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1>Shipping boxes</h1>
        <p class="page-header-sub">
            Define the boxes you ship in. Each product picks which box it ships in;
            the cart's shipping cost is the sum of boxes needed to pack the order.
        </p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<form method="post" id="boxes-form">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_all">

    <div class="card" style="overflow: visible;">
        <div class="card-header">
            <h2>Box presets</h2>
            <span class="text-muted text-sm"><?= count($boxes) ?> total</span>
        </div>

        <p class="text-muted text-sm" style="margin: 0 0 1rem;">
            <strong>Capacity</strong> is the approximate number of average items
            this box can hold — used by the cart packer to decide how many boxes
            you need. <strong>Sort order</strong> determines the order on this page
            and which box is the default fallback (smallest sort_order wins).
        </p>

        <div style="overflow-x: auto;">
        <table class="data-table" style="min-width: 720px; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 70px;">Active</th>
                    <th style="width: 110px;">Slug</th>
                    <th>Name</th>
                    <th style="width: 100px;">Price ($)</th>
                    <th style="width: 90px;">Capacity</th>
                    <th style="width: 70px;">Sort</th>
                    <th>Notes</th>
                    <th style="width: 60px;">Del</th>
                </tr>
            </thead>
            <tbody id="boxes-tbody">
                <?php foreach ($boxes as $i => $b): ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="hidden" name="box_id[<?= $i ?>]"
                                   value="<?= (int)$b['id'] ?>">
                            <input type="checkbox" name="box_active[<?= $i ?>]"
                                   value="1" <?= (int)$b['is_active'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="text" name="box_slug[<?= $i ?>]"
                                   value="<?= e($b['slug']) ?>"
                                   pattern="[a-z0-9_\-]{2,64}"
                                   required
                                   style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;">
                        </td>
                        <td>
                            <input type="text" name="box_name[<?= $i ?>]"
                                   value="<?= e($b['name']) ?>"
                                   required>
                        </td>
                        <td>
                            <input type="number" name="box_price[<?= $i ?>]"
                                   value="<?= e($b['price']) ?>"
                                   step="0.01" min="0" required>
                        </td>
                        <td>
                            <input type="number" name="box_capacity[<?= $i ?>]"
                                   value="<?= (int)$b['capacity'] ?>"
                                   step="1" min="1" required>
                        </td>
                        <td>
                            <input type="number" name="box_sort_order[<?= $i ?>]"
                                   value="<?= (int)$b['sort_order'] ?>"
                                   step="10">
                        </td>
                        <td>
                            <input type="text" name="box_notes[<?= $i ?>]"
                                   value="<?= e($b['notes'] ?? '') ?>"
                                   placeholder="optional">
                        </td>
                        <td style="text-align: center;">
                            <label style="cursor: pointer;" title="Tick to delete on next save">
                                <input type="checkbox" name="box_delete[]"
                                       value="<?= (int)$b['id'] ?>">
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div style="margin-top: 1rem; display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" class="btn btn-sm" id="add-row-btn">
                + Add box preset
            </button>
            <div style="flex: 1;"></div>
            <button type="submit" class="btn btn-primary">Save all changes</button>
        </div>
    </div>
</form>

<div class="card" style="margin-top: 1rem;">
    <div class="card-header"><h2>How it works</h2></div>
    <ol style="margin: 0; padding-left: 1.2rem; font-size: 13px; line-height: 1.65;">
        <li>Add a box for each shipping container you use. The defaults
            are USPS Flat Rate prices (early 2026); adjust them to whatever
            you actually pay (e.g. Commercial Plus rates).</li>
        <li>On each product (Shop → Products → edit), pick which box that
            product ships in. Products without an assignment use the
            smallest active box.</li>
        <li>The cart packer is greedy:
            <ul>
                <li>Group items by their box assignment.</li>
                <li>For each size, charge ⌈items ÷ capacity⌉ boxes.</li>
                <li>"Shrink-fit": items assigned to smaller boxes will
                    ride along free in a larger box that already has
                    spare capacity. So if you have a Medium-box product
                    and a Small-box product in the same cart, you usually
                    only pay for the Medium.</li>
            </ul>
        </li>
        <li>Disabling all boxes (or having none defined) makes the
            shop fall back to its built-in flat-shipping setting.</li>
    </ol>
</div>

<script>
(function () {
    // "Add box preset" appends a fresh row with blank values and a
    // unique array key. We use `new_<n>` so server-side knows it's
    // an insert (id=0) but each row still has a distinct key for the
    // parallel arrays.
    var tbody = document.getElementById('boxes-tbody');
    var btn   = document.getElementById('add-row-btn');
    var counter = <?= count($boxes) ?>;
    var newCounter = 0;

    btn.addEventListener('click', function () {
        var idx = 'new_' + (newCounter++);
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td style="text-align:center;">' +
                '<input type="hidden" name="box_id[' + idx + ']" value="0">' +
                '<input type="checkbox" name="box_active[' + idx + ']" value="1" checked>' +
            '</td>' +
            '<td><input type="text" name="box_slug[' + idx + ']" pattern="[a-z0-9_\\-]{2,64}" required style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;" placeholder="e.g. xl_box"></td>' +
            '<td><input type="text" name="box_name[' + idx + ']" required placeholder="e.g. Extra Large Box"></td>' +
            '<td><input type="number" name="box_price[' + idx + ']" value="0" step="0.01" min="0" required></td>' +
            '<td><input type="number" name="box_capacity[' + idx + ']" value="6" step="1" min="1" required></td>' +
            '<td><input type="number" name="box_sort_order[' + idx + ']" value="100" step="10"></td>' +
            '<td><input type="text" name="box_notes[' + idx + ']" placeholder="optional"></td>' +
            '<td style="text-align:center;"><input type="checkbox" name="box_delete[]" value="0" disabled title="Save first"></td>';
        tbody.appendChild(tr);
        tr.querySelector('input[name^="box_slug"]').focus();
    });

    // Confirm before bulk-delete
    document.getElementById('boxes-form').addEventListener('submit', function (e) {
        var deletes = document.querySelectorAll('input[name="box_delete[]"]:checked');
        if (deletes.length > 0) {
            if (!confirm('Delete ' + deletes.length + ' box preset(s)? Products assigned to these boxes will fall back to the smallest active box.')) {
                e.preventDefault();
            }
        }
    });
})();
</script>

<?php require $root . '/admin/partials/footer.php'; ?>
