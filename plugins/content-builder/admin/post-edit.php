<?php
/**
 * Content Builder — create / edit a post with the block builder.
 *
 * Fires 'content_edit_sidebar' so plugins (SEO, etc.) can add their own
 * fields to the editor sidebar. Those plugins are responsible for saving
 * their data — they can hook 'content_save_post' (fired below after save).
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.view');

// Cache-buster so upgrades always load fresh CSS/JS (browsers cache by URL).
// One source of truth for the bust token. It was a second hardcoded copy of
// ContentBuilder::ASSET_VER, so editing builder.js and bumping only the constant
// left this page serving the cached script — the change appears to do nothing.
$cbAssetVer = class_exists('ContentBuilder') ? ContentBuilder::ASSET_VER : '1.9.6';

$id   = (int)($_GET['id'] ?? 0);
$post = $id ? ContentBuilderAPI::getPost($id) : null;
$type = $post['type'] ?? ($_GET['type'] ?? 'page');
$pt   = PostType::get($type);
if (!$pt) { http_response_code(404); echo 'Unknown post type'; exit; }

$flash = null;

// ── Save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        Auth::requirePerm('content.edit');
        $action  = $_POST['action'] ?? 'draft';
        $publish = ($action === 'publish' && Auth::can('content.publish'));

        $layout = json_decode($_POST['layout'] ?? '[]', true);
        if (!is_array($layout)) $layout = [];

        $savedId = ContentBuilderAPI::savePost([
            'id'        => $id ?: null,
            'type'      => $type,
            'title'     => $_POST['title'] ?? '',
            'slug'      => $_POST['slug'] ?? ($_POST['title'] ?? ''),
            'layout'    => $layout,
            'excerpt'   => trim($_POST['excerpt'] ?? ''),
            'status'    => $publish ? 'published' : ($post['status'] ?? 'draft'),
            'parent_id' => (int)($_POST['parent_id'] ?? 0) ?: null,
        ]);

        if (PostType::supports($pt, 'taxonomy')) {
            ContentBuilderAPI::setPostTerms($savedId, $_POST['terms'] ?? []);
        }

        // Per-page layout width override ('' = follow global default).
        $w = $_POST['cb_width'] ?? '';
        ContentBuilderAPI::setMeta($savedId, 'cb_width',
            in_array($w, ['full','boxed','canvas'], true) ? $w : '');

        // Render mode: builder (themed blocks) or full_html (raw document).
        $rm = $_POST['cb_render_mode'] ?? 'builder';
        ContentBuilderAPI::setMeta($savedId, 'cb_render_mode',
            in_array($rm, ['builder','full_html'], true) ? $rm : 'builder');

        // Let other plugins persist their sidebar data (SEO meta, etc.)
        Hook::doAction('content_save_post', $savedId, $_POST);

        $flash = ['type'=>'success','msg'=>$publish ? 'Published.' : 'Saved.'];
        $id   = $savedId;
        $post = ContentBuilderAPI::getPost($id);
    }
}

$seedRenderMode = 'builder';
if (!$post) {
    $post = ['id'=>0,'type'=>$type,'title'=>'','slug'=>'','status'=>'draft',
             'layout'=>[],'excerpt'=>'','parent_id'=>null];

    // Start a new page from a premade template (?template=landing etc.)
    $tplKey = $_GET['template'] ?? '';
    if ($tplKey !== '' && ($tpl = ContentBuilderAPI::template($tplKey))) {
        $post['layout'] = $tpl['blocks'];
        // Designer pages carry their own render mode (e.g. full_html).
        if (!empty($tpl['mode'])) $seedRenderMode = $tpl['mode'];
    }
}

$pageTitle  = ($id ? 'Edit ' : 'New ') . $pt['singular'];
$currentNav = 'content-' . $type;

$taxonomies = PostType::supports($pt, 'taxonomy') ? Taxonomy::all($type) : [];
$pageWidth  = $id ? (string)ContentBuilderAPI::getMeta($id, 'cb_width', '') : '';
$renderMode = $id ? (string)ContentBuilderAPI::getMeta($id, 'cb_render_mode', 'builder') : $seedRenderMode;
$selectedTerms = $id ? array_column(ContentBuilderAPI::getPostTerms($id), 'id') : [];
$parents = $pt['hierarchical']
    ? ContentBuilderAPI::listPosts($type, ['status'=>'any','limit'=>200])
    : [];

require SLATE_ROOT . '/admin/partials/header.php';
?>
<?php $cbHasMedia = PluginLoader::isActive('media-library'); ?>
<?php if ($cbHasMedia): ?>
    <link rel="stylesheet" href="<?= e(SLATE_URL) ?>/plugins/media-library/assets/css/picker.css?v=<?= e($cbAssetVer) ?>">
    <script src="<?= e(SLATE_URL) ?>/plugins/media-library/assets/js/picker.js?v=<?= e($cbAssetVer) ?>"></script>
<?php endif; ?>
<script>window.CB_HAS_MEDIA = <?= $cbHasMedia ? 'true' : 'false' ?>;</script>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => $pt['label'], 'href' => 'posts.php?type=' . urlencode($type)],
    ['label' => $id ? 'Edit' : 'New'],
]); ?>

<div class="page-header">
    <div>
        <h1><?= $id ? 'Edit' : 'New' ?> <?= e($pt['singular']) ?></h1>
        <p class="page-header-sub">Build the page from blocks, then save or publish.</p>
    </div>
    <span class="badge badge-<?= $post['status']==='published'?'active':'info' ?>">
        <?= e(ucfirst($post['status'])) ?>
    </span>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<form method="post" id="cb-form">
    <?= csrf_field() ?>
    <input type="hidden" name="layout" id="cb-layout"
           value="<?= e(json_encode($post['layout'] ?: [])) ?>">
    <input type="hidden" name="action" id="cb-action" value="draft">

    <!-- Sticky action bar — primary actions always reachable while scrolling. -->
    <div class="cb-toolbar">
        <div class="cb-toolbar-info">
            <span class="cb-toolbar-title"><?= $id ? 'Edit' : 'New' ?> <?= e($pt['singular']) ?></span>
            <span class="badge badge-<?= $post['status']==='published'?'active':'info' ?>"><?= e(ucfirst($post['status'])) ?></span>
        </div>
        <div class="cb-toolbar-actions">
            <?php if ($id): ?>
                <a class="btn btn-sm" target="_blank" rel="noopener"
                   href="<?= e(ContentBuilderAPI::publicUrl($type, $post['slug'], $post['status'] !== 'published')) ?>">Preview&nbsp;→</a>
            <?php endif; ?>
            <?php if (Auth::can('content.edit')): ?>
                <button type="submit" class="btn btn-sm" onclick="document.getElementById('cb-action').value='draft'">Save</button>
            <?php endif; ?>
            <?php if (Auth::can('content.publish')): ?>
                <button type="submit" class="btn btn-sm btn-primary" onclick="document.getElementById('cb-action').value='publish'">Publish</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="cb-editor-grid">
        <div class="cb-main">
            <div class="card">
                <div class="field">
                    <label class="field-label" for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="255"
                           value="<?= e($post['title']) ?>" placeholder="Enter title">
                </div>
                <div class="field">
                    <label class="field-label" for="slug">Slug</label>
                    <input type="text" id="slug" name="slug"
                           value="<?= e($post['slug']) ?>" placeholder="auto from title">
                </div>
            </div>

            <?php if (!$id && empty($post['layout'])): ?>
            <div class="card">
                <div class="card-header"><h2>Start from a template</h2></div>
                <p class="text-sub">Pick a starting point, or just add blocks below.</p>
                <div class="cb-template-grid">
                    <?php foreach (ContentBuilderAPI::templates() as $key => $tpl): ?>
                        <a class="cb-template-card"
                           href="post-edit.php?type=<?= e(urlencode($type)) ?>&template=<?= e(urlencode($key)) ?>">
                            <strong><?= e($tpl['label']) ?></strong>
                            <span class="text-muted text-sm"><?= count($tpl['blocks']) ?> blocks</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($id): ?>
            <div class="card cb-live-preview-card">
                <div class="card-header cb-live-preview-header">
                    <div>
                        <h2>Live canvas preview</h2>
                        <p class="text-muted text-sm">Updates as you edit. Unsaved changes stay in this preview until you save.</p>
                    </div>
                    <button type="button" class="btn btn-sm" id="cb-preview-refresh">Refresh</button>
                </div>
                <div class="cb-live-preview-frame-wrap" id="cb-live-preview-wrap">
                    <iframe
                        id="cb-live-preview"
                        title="Live canvas preview"
                        loading="eager"
                        src="<?= e(SLATE_URL) ?>/plugins/content-builder/public/canvas-preview.php?id=<?= (int)$id ?>"
                    ></iframe>
                </div>
            </div>
            <?php else: ?>
            <div class="card cb-live-preview-card cb-live-preview-placeholder">
                <div class="card-header"><h2>Live canvas preview</h2></div>
                <p class="text-muted text-sm">Save this page once to enable the live canvas preview.</p>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header cb-blocks-header">
                    <h2>Blocks</h2>
                    <div class="cb-blocks-actions">
                        <button type="button" class="btn btn-sm" id="cb-add-template">
                            ▦ Page templates
                        </button>
                        <button type="button" class="btn btn-sm" id="cb-add-block-toggle" aria-expanded="false">
                            + Add block
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" id="cb-add-section">
                            + Add a section
                        </button>
                    </div>
                </div>
                <!-- Block palette: collapsed by default so the editor stays compact. -->
                <div id="cb-palette" class="cb-palette" hidden></div>
                <div id="cb-canvas" class="cb-canvas" aria-live="polite"></div>
            </div>

            <!-- Premade section gallery (drop-in patterns) -->
            <div id="cb-section-modal" class="cb-modal" hidden>
                <div class="cb-modal-backdrop" data-cb-close></div>
                <div class="cb-modal-panel" role="dialog" aria-modal="true" aria-label="Premade sections">
                    <div class="cb-modal-head">
                        <h2>Add a premade section</h2>
                        <button type="button" class="cb-icon-btn" data-cb-close aria-label="Close">✕</button>
                    </div>
                    <p class="text-muted text-sm cb-modal-sub">Pick a ready-made section. It’s added to the bottom of your page — then just replace the text and images.</p>
                    <div id="cb-section-tabs" class="cb-section-tabs"></div>
                    <div id="cb-section-grid" class="cb-section-grid"></div>
                </div>
            </div>

            <!-- Full-page template library (apply a whole-page design) -->
            <div id="cb-template-modal" class="cb-modal" hidden>
                <div class="cb-modal-backdrop" data-cb-close></div>
                <div class="cb-modal-panel" role="dialog" aria-modal="true" aria-label="Page templates">
                    <div class="cb-modal-head">
                        <h2>Start from a page template</h2>
                        <button type="button" class="cb-icon-btn" data-cb-close aria-label="Close">✕</button>
                    </div>
                    <p class="text-muted text-sm cb-modal-sub">Pick a full-page design to build from. It replaces the current blocks — then edit the content and images to make it yours.</p>
                    <div id="cb-template-tabs" class="cb-section-tabs"></div>
                    <div id="cb-template-grid" class="cb-section-grid"></div>
                </div>
            </div>
        </div>

        <aside class="cb-sidebar">
            <div class="card">
                <div class="card-header"><h2>Publish</h2></div>
                <?php if (Auth::can('content.edit')): ?>
                    <button type="submit" class="btn btn-block"
                            onclick="document.getElementById('cb-action').value='draft'">
                        Save draft
                    </button>
                <?php endif; ?>
                <?php if (Auth::can('content.publish')): ?>
                    <button type="submit" class="btn btn-primary btn-block mt-2"
                            onclick="document.getElementById('cb-action').value='publish'">
                        Publish
                    </button>
                <?php endif; ?>
                <?php if ($id): ?>
                    <a class="btn btn-sm btn-block mt-2" target="_blank"
                       href="<?= e(ContentBuilderAPI::publicUrl($type, $post['slug'], $post['status'] !== 'published')) ?>">
                        <?= $post['status'] === 'published' ? 'View page →' : 'Preview draft →' ?>
                    </a>
                    <button type="button" class="btn btn-sm btn-block mt-2" id="cb-copy-link"
                            data-url="<?= e(ContentBuilderAPI::publicUrl($type, $post['slug'])) ?>">
                        Copy public link
                    </button>
                <?php endif; ?>
            </div>

            <!-- Secondary page settings, grouped + collapsed to keep the sidebar compact. -->
            <details class="card cb-side-details">
                <summary class="cb-side-summary">Page settings</summary>
                <div class="field">
                    <label class="field-label">Render mode</label>
                    <select name="cb_render_mode" class="field-input">
                        <option value="builder"   <?= $renderMode==='builder'?'selected':'' ?>>Builder (themed blocks + header/footer)</option>
                        <option value="full_html" <?= $renderMode==='full_html'?'selected':'' ?>>Full custom HTML (raw document, no shell)</option>
                    </select>
                    <small class="text-muted">Full HTML: add a single Raw HTML block with your complete &lt;!doctype html&gt;… document. The theme, header, and footer are skipped.</small>
                </div>
                <div class="field">
                    <label class="field-label">Page width</label>
                    <select name="cb_width" class="field-input">
                        <option value=""       <?= $pageWidth===''?'selected':'' ?>>Use site default</option>
                        <option value="boxed"  <?= $pageWidth==='boxed'?'selected':'' ?>>Boxed</option>
                        <option value="full"   <?= $pageWidth==='full'?'selected':'' ?>>Full</option>
                        <option value="canvas" <?= $pageWidth==='canvas'?'selected':'' ?>>Canvas (edge to edge)</option>
                    </select>
                </div>
                <?php if ($pt['hierarchical'] && $parents): ?>
                <div class="field">
                    <label class="field-label">Parent</label>
                    <select name="parent_id" class="field-input">
                        <option value="">— none —</option>
                        <?php foreach ($parents as $par):
                            if ((int)$par['id'] === (int)$post['id']) continue; ?>
                            <option value="<?= (int)$par['id'] ?>"
                                <?= (int)$post['parent_id']===(int)$par['id']?'selected':'' ?>>
                                <?= e($par['title'] ?: '(untitled)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </details>

            <?php if (PostType::supports($pt, 'editor')): ?>
            <div class="card">
                <div class="card-header"><h2>Excerpt</h2></div>
                <div class="field">
                    <textarea name="excerpt" rows="3" class="field-input"
                              placeholder="Short summary"><?= e($post['excerpt'] ?? '') ?></textarea>
                </div>
            </div>
            <?php endif; ?>

            <?php foreach ($taxonomies as $tax): ?>
            <div class="card">
                <div class="card-header"><h2><?= e($tax['label']) ?></h2></div>
                <div class="cb-terms">
                    <?php foreach (ContentBuilderAPI::getTerms($tax['slug']) as $term): ?>
                        <label class="cb-term">
                            <input type="checkbox" name="terms[]" value="<?= (int)$term['id'] ?>"
                                <?= in_array($term['id'], $selectedTerms) ? 'checked' : '' ?>>
                            <?= e($term['name']) ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!ContentBuilderAPI::getTerms($tax['slug'])): ?>
                        <p class="text-muted text-sm">No terms.
                           <a href="terms.php?taxonomy=<?= e(urlencode($tax['slug'])) ?>">Add some →</a></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
            /* Extension point: SEO and other plugins inject sidebar cards here. */
            Hook::doAction('content_edit_sidebar', $post);
            ?>
        </aside>
    </div>
</form>

<script>window.CB_PALETTE = <?= BlockRegistry::paletteJson() ?>;</script>
<script>window.CB_PATTERNS = <?= PatternLibrary::paletteJson() ?>;</script>
<script>window.CB_TEMPLATES = <?= ContentBuilderAPI::templatesJson() ?>;</script>
<?php /* Thumbnails for media the editor holds only a KEY for — resolved server
         side, because that is where the key -> media mapping lives. */ ?>
<script>window.CB_MEDIA_URLS = <?= json_encode(
    ContentBuilderAPI::mediaPreviewMap($post['layout'] ?? []),
    JSON_UNESCAPED_SLASHES
) ?>;</script>
<script src="<?= e(SLATE_URL) ?>/plugins/content-builder/admin/assets/sortable.min.js?v=<?= e($cbAssetVer) ?>"></script>
<script src="<?= e(SLATE_URL) ?>/plugins/content-builder/admin/assets/block-reorder.js?v=<?= e($cbAssetVer) ?>"></script>
<script>window.CB_PREVIEW_ID = <?= (int)$id ?>;</script>
<script src="<?= e(SLATE_URL) ?>/plugins/content-builder/admin/assets/builder.js?v=<?= e($cbAssetVer) ?>"></script>
<script>
(function(){
  var btn=document.getElementById('cb-copy-link');
  if(!btn) return;
  btn.addEventListener('click',function(){
    var url=btn.getAttribute('data-url')||'';
    var done=function(){var t=btn.textContent;btn.textContent='Copied!';setTimeout(function(){btn.textContent=t;},1500);};
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done,done);}
    else{var i=document.createElement('input');i.value=url;document.body.appendChild(i);i.select();try{document.execCommand('copy');}catch(e){}document.body.removeChild(i);done();}
  });
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
