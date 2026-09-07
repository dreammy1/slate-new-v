<?php
/**
 * Shop — Categories admin (single-level for now).
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.manage_products');

$pageTitle  = __('shop_categories', 'Categories');
$currentNav = 'shop-categories';

$tid   = current_tenant_id();
$flash = null;
$editing = null;
$mode = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $slug   = trim((string)($_POST['slug'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $order  = (int)($_POST['display_order'] ?? 0);

        if ($slug === '' && $name !== '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');
        }

        if ($action === 'create' || $action === 'update') {
            if ($name === '' || $slug === '') {
                $flash = ['type' => 'error', 'msg' => 'Name and slug are required.'];
                $mode = 'edit';
                // Keys must match what the editor template reads —
                // 'description' (not 'desc') and 'display_order' (not 'order').
                $editing = [
                    'id'            => $id,
                    'name'          => $name,
                    'slug'          => $slug,
                    'description'   => $desc,
                    'display_order' => $order,
                ];
            } else {
                $dup = Database::row(
                    "SELECT id FROM shop_categories WHERE tenant_id = ? AND slug = ? AND id != ?",
                    [$tid, $slug, $id]);
                if ($dup) {
                    $flash = ['type' => 'error', 'msg' => 'Another category has that slug.'];
                    $mode = 'edit';
                    $editing = [
                        'id'            => $id,
                        'name'          => $name,
                        'slug'          => $slug,
                        'description'   => $desc,
                        'display_order' => $order,
                    ];
                } elseif ($action === 'create') {
                    $newId = Database::insert('shop_categories', [
                        'tenant_id' => $tid, 'slug' => $slug, 'name' => $name,
                        'description' => $desc !== '' ? $desc : null,
                        'display_order' => $order,
                    ]);
                    AuditLog::record('shop.category_created', "category#$newId");
                    $flash = ['type' => 'success', 'msg' => sprintf('Category "%s" created.', $name)];
                } else {
                    Database::update('shop_categories', [
                        'slug' => $slug, 'name' => $name,
                        'description' => $desc !== '' ? $desc : null,
                        'display_order' => $order,
                    ], 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('shop.category_updated', "category#$id");
                    $flash = ['type' => 'success', 'msg' => 'Category updated.'];
                    $mode = 'edit';
                    $editing = Database::row("SELECT * FROM shop_categories WHERE id = ?", [$id]);
                }
            }
        }
        elseif ($action === 'delete' && $id > 0) {
            $inUse = (int)Database::value(
                "SELECT COUNT(*) FROM shop_products WHERE category_id = ? AND tenant_id = ?",
                [$id, $tid]);
            if ($inUse > 0) {
                $flash = ['type' => 'error',
                    'msg' => sprintf('%d product(s) still use this category. Reassign them first.', $inUse)];
            } else {
                Database::delete('shop_categories', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('shop.category_deleted', "category#$id");
                $flash = ['type' => 'success', 'msg' => 'Category deleted.'];
            }
        }
    }
}

if ($mode === 'list') {
    if (isset($_GET['new'])) {
        $mode = 'edit';
        $editing = ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'display_order' => 0];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $row = Database::row("SELECT * FROM shop_categories WHERE id = ? AND tenant_id = ?", [$id, $tid]);
        if ($row) { $editing = $row; $mode = 'edit'; }
    }
}

require $root . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Categories'] + ($mode === 'edit'
        ? ['href' => plugin_url('shop', 'admin/categories.php')] : []),
]);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'edit'):
    $isNew = ((int)($editing['id'] ?? 0)) === 0;
?>
    <div class="page-header">
        <div>
            <h1><?= $isNew ? 'New category' : e($editing['name'] ?: 'Edit category') ?></h1>
        </div>
        <a href="<?= e(plugin_url('shop', 'admin/categories.php')) ?>" class="btn btn-ghost">← Back</a>
    </div>

    <div class="card">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="slug">Slug</label>
                    <input type="text" id="slug" name="slug" maxlength="100"
                           value="<?= e($editing['slug'] ?? '') ?>"
                           placeholder="auto-generated">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="description">Description</label>
                <textarea id="description" name="description"
                          rows="3"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
            <div class="field" style="max-width:200px;">
                <label class="field-label" for="display_order">Display order</label>
                <input type="number" id="display_order" name="display_order"
                       value="<?= (int)($editing['display_order'] ?? 0) ?>">
                <div class="field-hint">Lower numbers appear first.</div>
            </div>
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create' : 'Save changes' ?></button>
        </form>
    </div>

    <?php if (!$isNew): ?>
    <div class="card">
        <div class="card-header"><h2 class="text-danger">Danger zone</h2></div>
        <form method="post"
              onsubmit="return confirm(<?= e(json_encode(sprintf('Delete category "%s"?', $editing['name']))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <button type="submit" class="btn btn-danger">Delete category</button>
        </form>
    </div>
    <?php endif; ?>

<?php else:
    $cats = ShopAPI::categories(true);
?>
    <div class="page-header">
        <div>
            <h1>Categories</h1>
            <p class="page-header-sub">Organize products into categories shown in your storefront.</p>
        </div>
        <a href="?new" class="btn btn-primary">+ New category</a>
    </div>

    <?php if (empty($cats)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title">No categories yet</div>
                <p>Create your first category to organize products.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($cats as $c):
                ob_start(); ?>
                <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                <a href="<?= e(SLATE_URL) ?>/shop/category/<?= e($c['slug']) ?>"
                   class="btn btn-sm" target="_blank">View</a>
                <?php $actions = ob_get_clean();

                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr($c['name'], 0, 2)),
                    'avatar_color' => 'accent',
                    'title'        => $c['name'],
                    'meta'         => $c['slug'] . ' · ' . (int)$c['product_count'] . ' products',
                    'badge'        => [(int)$c['product_count'] . ' products', 'info'],
                    'detail'       => [
                        'Slug'        => $c['slug'],
                        'Description' => $c['description'] ?: '—',
                        'Products'    => (int)$c['product_count'],
                        'Order'       => (int)$c['display_order'],
                    ],
                    'actions' => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
