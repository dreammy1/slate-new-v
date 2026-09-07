<?php
/** React Site Bridge — site list and creation screen. */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
Auth::require();
Auth::requirePerm('react-site-bridge.manage');
ReactSiteBridgeAPI::ensureSchema();

$pageTitle = __('react_site_bridge', 'React Sites');
$currentNav = 'react-site-bridge';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'import_package') {
        try {
            $result = ReactSiteBridgeAPI::importPackage((array)($_FILES['site_package'] ?? []), !empty($_POST['publish_initial']), !empty($_POST['replace_existing']));
            AuditLog::record('react_site_bridge.package_imported', 'site#' . $result['site_id'], ['site_key' => $result['site_key'], 'published' => $result['published'], 'replaced' => $result['replaced']]);
            header('Location: ' . SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . (int)$result['site_id'] . '&imported=1');
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    } elseif (($_POST['_action'] ?? '') === 'create_site') {
        try {
            $id = ReactSiteBridgeAPI::createSite(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['site_key'] ?? ''),
                (string)($_POST['public_origin'] ?? ''),
                (string)($_POST['preview_origin'] ?? '')
            );
            AuditLog::record('react_site_bridge.site_created', 'site#' . $id);
            header('Location: ' . SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $id . '&created=1');
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}
$sites = ReactSiteBridgeAPI::listSites();
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => __('react_site_bridge', 'React Sites')]]); ?>
<div class="page-header">
  <div><h1>React Sites</h1><p class="page-header-sub">Publish tenant-scoped content and media manifests to static React frontends.</p></div>
</div>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<div class="card mb-3">
  <div class="card-header"><h2>Import a prepared site package</h2></div>
  <p class="text-muted">Use the single ZIP supplied for a React site. Slate will create the site, import editable route content, move approved media into this tenant’s Media Library, map assets, and optionally publish the first manifest.</p>
  <form method="post" enctype="multipart/form-data" class="rsc-import-form">
    <?= csrf_field() ?><input type="hidden" name="_action" value="import_package">
    <div class="rsc-import-row"><div class="field"><label class="field-label" for="site_package">React Site Package <span class="field-required">*</span></label><input id="site_package" name="site_package" type="file" accept=".zip,application/zip" required><p class="field-help">ZIP only; up to 55 MB. The package must contain a signed-off site-package.json and a media directory.</p></div><div class="rsc-checks"><label class="check-row"><input type="checkbox" name="publish_initial" value="1" checked> Publish the initial manifest after import</label><label class="check-row"><input type="checkbox" name="replace_existing" value="1"> Replace an existing site with the same key</label><p class="field-help">Replacement removes the site’s bridge documents, mappings, and revisions, but keeps Media Library files available for cleanup.</p></div></div>
    <button class="btn btn-primary" type="submit">Import site package</button>
  </form>
</div>
<div class="card mb-3">
  <div class="card-header"><h2>Create a React site</h2></div>
  <form method="post" class="rsc-form">
    <?= csrf_field() ?><input type="hidden" name="_action" value="create_site">
    <div class="rsc-grid">
      <div class="field"><label class="field-label" for="name">Site name <span class="field-required">*</span></label><input id="name" name="name" required maxlength="190" placeholder="Kaimana"></div>
      <div class="field"><label class="field-label" for="site_key">Site key <span class="field-required">*</span></label><input id="site_key" name="site_key" required maxlength="80" pattern="[a-z0-9-]+" placeholder="kaimana"><p class="field-help">Lowercase letters, numbers, and hyphens only.</p></div>
      <div class="field"><label class="field-label" for="public_origin">Published frontend origin</label><input id="public_origin" type="url" name="public_origin" placeholder="https://www.example.com"></div>
      <div class="field"><label class="field-label" for="preview_origin">Preview frontend origin</label><input id="preview_origin" type="url" name="preview_origin" placeholder="https://preview.example.com"></div>
    </div>
    <button class="btn btn-primary" type="submit">Create site</button>
  </form>
</div>
<div class="card">
  <div class="card-header"><h2>Sites</h2><span class="text-muted text-sm"><?= count($sites) ?></span></div>
  <?php if (!$sites): ?><div class="empty"><div class="empty-title">No React sites yet</div><p>Create one above, then add route documents and media mappings.</p></div>
  <?php else: ?><div class="data-list"><?php foreach ($sites as $site): ?>
    <?php $manageUrl = SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . (int)$site['id']; $manifestUrl = rtrim(SLATE_URL, '/') . '/site-data/' . rawurlencode((string)$site['public_id']) . '/manifest'; $frontendUrl = (string)($site['preview_origin'] ?: $site['public_origin']); ?>
    <div class="rsc-site-row"><div class="rsc-site-summary"><span class="rsc-avatar">RS</span><div><strong><?= e($site['name']) ?></strong><span><?= e($site['site_key']) ?> · <?= e($site['status']) ?><?= !empty($site['active_version']) ? ' · v' . (int)$site['active_version'] : ' · Not published' ?></span></div></div><div class="rsc-site-actions"><a class="btn btn-sm btn-primary" href="<?= e($manageUrl) ?>">Open / manage</a><?php if (!empty($site['active_revision_id'])): ?><a class="btn btn-sm" target="_blank" rel="noopener" href="<?= e($manifestUrl) ?>">View manifest</a><?php endif; ?><?php if ($frontendUrl): ?><a class="btn btn-sm" target="_blank" rel="noopener" href="<?= e($frontendUrl) ?>">Preview frontend</a><?php endif; ?></div></div>
  <?php endforeach; ?></div><?php endif; ?>
</div>
<style>.rsc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.rsc-import-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.8fr);gap:16px;align-items:end;margin:0 0 14px}.rsc-checks{padding-bottom:3px}.check-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:.9rem}.field-help{margin:5px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}.mb-3{margin-bottom:18px}.rsc-site-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 0;border-top:1px solid var(--border,#e5e7eb)}.rsc-site-row:first-child{border-top:0}.rsc-site-summary{display:flex;align-items:center;gap:12px}.rsc-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--accent-soft,#fce7f3);color:var(--accent,#be185d);font-size:.75rem;font-weight:700}.rsc-site-summary strong,.rsc-site-summary span{display:block}.rsc-site-summary span{margin-top:3px;color:var(--muted,#6b7280);font-size:.84rem}.rsc-site-actions{display:flex;flex-wrap:wrap;gap:7px;justify-content:flex-end}@media(max-width:720px){.rsc-grid,.rsc-import-row{grid-template-columns:1fr}.check-row{margin:0 0 8px}.rsc-site-row{display:block}.rsc-site-actions{justify-content:flex-start;margin-top:12px}}</style>
<?php require $root . '/admin/partials/footer.php'; ?>
