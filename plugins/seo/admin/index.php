<?php
/**
 * SEO — site-wide settings.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('seo.manage');

$pageTitle  = 'SEO';
$currentNav = 'seo-settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        SEOAPI::setSetting('site_name',           trim($_POST['site_name'] ?? ''));
        SEOAPI::setSetting('title_suffix',        trim($_POST['title_suffix'] ?? ''));
        SEOAPI::setSetting('default_description', trim($_POST['default_description'] ?? ''));
        $flash = ['type'=>'success','msg'=>'Settings saved.'];
    }
}

$s = SEOAPI::allSettings();
$cbActive = class_exists('ContentBuilderAPI');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'SEO'],
]); ?>

<div class="page-header">
    <div><h1>SEO settings</h1>
    <p class="page-header-sub">Site-wide defaults. Per-page overrides live in each page's editor sidebar.</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (!$cbActive): ?>
    <div class="alert alert-info" role="status">
        Content Builder isn't active. SEO settings still save, but per-page meta
        fields and rendered tags only appear once Content Builder is enabled.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Defaults</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="site_name">Site name</label>
            <input type="text" id="site_name" name="site_name"
                   value="<?= e($s['site_name'] ?? '') ?>" placeholder="Chef Gregory's">
        </div>
        <div class="field">
            <label class="field-label" for="title_suffix">Title suffix</label>
            <input type="text" id="title_suffix" name="title_suffix"
                   value="<?= e($s['title_suffix'] ?? '') ?>" placeholder="— Chef Gregory's">
            <small class="text-muted">Appended to every page title when not already present.</small>
        </div>
        <div class="field">
            <label class="field-label" for="default_description">Default meta description</label>
            <textarea id="default_description" name="default_description" rows="3"
                      class="field-input" placeholder="Used when a page has no description of its own"><?= e($s['default_description'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save settings</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>How per-page SEO works</h2></div>
    <p class="text-sub">
        When Content Builder is active, this plugin adds an <strong>SEO</strong>
        card to every page/post editor (meta title, description, canonical, social
        image, noindex). Those values are stored as post meta and rendered into the
        page <code>&lt;head&gt;</code> via the <code>content_head_tags</code> hook —
        no template edits required.
    </p>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
