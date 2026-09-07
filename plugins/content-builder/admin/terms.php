<?php
/**
 * Content Builder — manage terms within a taxonomy.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.manage_types');

$taxSlug = $_GET['taxonomy'] ?? 'category';
$tax     = Taxonomy::get($taxSlug);
if (!$tax) { http_response_code(404); echo 'Unknown taxonomy'; exit; }

$pageTitle  = $tax['label'] . ' — Terms';
$currentNav = 'content-tax';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        $act = $_POST['action'] ?? 'create';
        if ($act === 'delete') {
            ContentBuilderAPI::deleteTerm((int)($_POST['id'] ?? 0));
            $flash = ['type'=>'success','msg'=>'Term deleted.'];
        } else {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                $flash = ['type'=>'error','msg'=>'Name required.'];
            } else {
                ContentBuilderAPI::addTerm($taxSlug, $name,
                    (int)($_POST['parent_id'] ?? 0) ?: null);
                $flash = ['type'=>'success','msg'=>'Term added.'];
            }
        }
    }
}

$terms = ContentBuilderAPI::getTerms($taxSlug);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Taxonomies', 'href' => 'taxonomies.php'],
    ['label' => $tax['label']],
]); ?>

<div class="page-header">
    <div><h1><?= e($tax['label']) ?> terms</h1>
    <p class="page-header-sub">Terms in the <code><?= e($taxSlug) ?></code> taxonomy.</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Add a term</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="name">Name</label>
            <input type="text" id="name" name="name" required placeholder="News">
        </div>
        <?php if ($tax['hierarchical'] && $terms): ?>
        <div class="field">
            <label class="field-label" for="parent_id">Parent</label>
            <select id="parent_id" name="parent_id" class="field-input">
                <option value="">— none —</option>
                <?php foreach ($terms as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Add term</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Terms</h2>
        <span class="text-muted text-sm"><?= count($terms) ?></span></div>
    <?php if (!$terms): ?>
        <div class="empty"><div class="empty-title">No terms yet</div></div>
    <?php else: ?>
        <div class="cb-rows">
            <?php foreach ($terms as $t): ?>
                <div class="cb-row">
                    <div class="cb-row-main">
                        <strong><?= e($t['name']) ?></strong>
                        <div class="text-muted text-sm">slug: <code><?= e($t['slug']) ?></code></div>
                    </div>
                    <div class="cb-row-actions">
                        <form method="post" onsubmit="return confirm('Delete term?')" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
