<?php
/**
 * Content Builder — manage taxonomies.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.manage_types');

$pageTitle  = 'Taxonomies';
$currentNav = 'content-tax';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postTypes = array_values($_POST['post_types'] ?? []);
    try {
        ContentBuilderAPI::registerTaxonomy([
            'slug'         => $_POST['slug'] ?? '',
            'label'        => $_POST['label'] ?? '',
            'hierarchical' => !empty($_POST['hierarchical']),
            'post_types'   => $postTypes ?: ['post'],
        ]);
        $flash = ['type'=>'success','msg'=>'Taxonomy saved.'];
    } catch (\Throwable $ex) {
        $flash = ['type'=>'error','msg'=>$ex->getMessage()];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type'=>'error','msg'=>'Security check failed.'];
}

$taxonomies = Taxonomy::all();
$allTypes   = PostType::all();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Taxonomies'],
]); ?>

<div class="page-header">
    <div><h1>Taxonomies</h1>
    <p class="page-header-sub">Group content with categories, tags, or your own taxonomies.</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Add a taxonomy</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="label">Label</label>
            <input type="text" id="label" name="label" required placeholder="Genres">
        </div>
        <div class="field">
            <label class="field-label" for="slug">Slug</label>
            <input type="text" id="slug" name="slug" required placeholder="genre">
        </div>
        <div class="field">
            <label class="cb-term"><input type="checkbox" name="hierarchical" value="1"> Hierarchical (like Categories)</label>
        </div>
        <div class="field">
            <label class="field-label">Attach to post types</label>
            <?php foreach ($allTypes as $t): ?>
                <label class="cb-term"><input type="checkbox" name="post_types[]" value="<?= e($t['slug']) ?>"
                    <?= $t['slug']==='post'?'checked':'' ?>> <?= e($t['label']) ?></label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save taxonomy</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Registered taxonomies</h2></div>
    <div class="cb-rows">
        <?php foreach ($taxonomies as $tax): ?>
            <div class="cb-row">
                <div class="cb-row-main">
                    <strong><?= e($tax['label']) ?></strong>
                    <div class="text-muted text-sm">
                        slug: <code><?= e($tax['slug']) ?></code> ·
                        <?= $tax['hierarchical'] ? 'hierarchical' : 'flat' ?> ·
                        <?= e(implode(', ', $tax['post_types'])) ?>
                    </div>
                </div>
                <div class="cb-row-actions">
                    <a class="btn btn-sm" href="terms.php?taxonomy=<?= e(urlencode($tax['slug'])) ?>">Manage terms &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
