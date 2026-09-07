<?php
/**
 * Content Builder — manage custom post types.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.manage_types');

$pageTitle  = 'Post Types';
$currentNav = 'content-types';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        $act = $_POST['action'] ?? 'create';
        if ($act === 'delete') {
            ContentBuilderAPI::deletePostType((int)($_POST['id'] ?? 0));
            $flash = ['type'=>'success','msg'=>'Post type deleted.'];
        } else {
            $supports = array_values(array_intersect(
                ['title','editor','excerpt','thumbnail','taxonomy'],
                $_POST['supports'] ?? []));
            try {
                ContentBuilderAPI::registerPostType([
                    'slug'         => $_POST['slug'] ?? '',
                    'label'        => $_POST['label'] ?? '',
                    'singular'     => $_POST['singular'] ?? '',
                    'hierarchical' => !empty($_POST['hierarchical']),
                    'supports'     => $supports ?: ['title','editor'],
                ]);
                $flash = ['type'=>'success','msg'=>'Post type saved.'];
            } catch (\Throwable $ex) {
                $flash = ['type'=>'error','msg'=>'Could not save: ' . $ex->getMessage()];
            }
        }
    }
}

$types = PostType::all();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Post Types'],
]); ?>

<div class="page-header">
    <div><h1>Post Types</h1>
    <p class="page-header-sub">Built-ins (Pages, Posts) can't be deleted. Add your own below.</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Add a post type</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="label">Plural label</label>
            <input type="text" id="label" name="label" required placeholder="Products">
        </div>
        <div class="field">
            <label class="field-label" for="singular">Singular label</label>
            <input type="text" id="singular" name="singular" required placeholder="Product">
        </div>
        <div class="field">
            <label class="field-label" for="slug">Slug</label>
            <input type="text" id="slug" name="slug" required placeholder="product">
        </div>
        <div class="field">
            <label class="cb-term"><input type="checkbox" name="hierarchical" value="1"> Hierarchical (like Pages)</label>
        </div>
        <div class="field">
            <label class="field-label">Supports</label>
            <?php foreach (['title','editor','excerpt','thumbnail','taxonomy'] as $s): ?>
                <label class="cb-term"><input type="checkbox" name="supports[]" value="<?= $s ?>"
                    <?= in_array($s,['title','editor'])?'checked':'' ?>> <?= e($s) ?></label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save post type</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Registered types</h2></div>
    <div class="cb-rows">
        <?php foreach ($types as $t): ?>
            <div class="cb-row">
                <div class="cb-row-main">
                    <strong><?= e($t['label']) ?></strong>
                    <?php if ($t['is_builtin']): ?><span class="badge badge-info">built-in</span><?php endif; ?>
                    <div class="text-muted text-sm">
                        slug: <code><?= e($t['slug']) ?></code> ·
                        <?= $t['hierarchical'] ? 'hierarchical' : 'flat' ?> ·
                        supports: <?= e(implode(', ', $t['supports'])) ?>
                    </div>
                </div>
                <div class="cb-row-actions">
                    <a class="btn btn-sm" href="posts.php?type=<?= e(urlencode($t['slug'])) ?>">View items &rarr;</a>
                    <?php if (!$t['is_builtin']): ?>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Delete this post type?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
