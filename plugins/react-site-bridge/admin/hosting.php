<?php
/**
 * React Site Bridge — direct Slate-hosted React release manager.
 * Administrators upload a bounded static release ZIP; the public router then
 * serves it from /react-sites/<public-id>/ without an iframe or external host.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
Auth::require();
Auth::requirePerm('react-site-bridge.manage');
ReactSiteBridgeAPI::ensureSchema();

$siteId = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$site = ReactSiteBridgeAPI::getSite($siteId);
if (!$site) { http_response_code(404); echo 'React site not found.'; exit; }
$pageTitle = $site['name'] . ' · Hosted release';
$currentNav = 'react-site-bridge';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    else {
        try {
            $action = (string)($_POST['_action'] ?? '');
            if ($action === 'upload_hosted_release') {
                $release = ReactSiteBridgeAPI::importHostedRelease($siteId, $_FILES['release_package'] ?? []);
                AuditLog::record('react_site_bridge.hosted_release_uploaded', 'site#' . $siteId . ' files#' . (int)$release['file_count']);
                $flash = ['type' => 'success', 'msg' => 'Slate-hosted React release uploaded and activated.'];
            } elseif ($action === 'remove_hosted_release') {
                ReactSiteBridgeAPI::removeHostedRelease($siteId);
                AuditLog::record('react_site_bridge.hosted_release_removed', 'site#' . $siteId);
                $flash = ['type' => 'success', 'msg' => 'Slate-hosted release removed. The site manifest and editable content were retained.'];
            }
        } catch (Throwable $e) { $flash = ['type' => 'error', 'msg' => $e->getMessage()]; }
    }
}

$release = ReactSiteBridgeAPI::hostedRelease($siteId);
$hostedUrl = ReactSiteBridgeAPI::hostedUrl($siteId);
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => 'React Sites', 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/index.php'], ['label' => $site['name'], 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId], ['label' => 'Hosted release']]); ?>
<div class="page-header"><div><h1>Hosted React release</h1><p class="page-header-sub">Serve <?= e($site['name']) ?> directly from Slate, without an iframe.</p></div><div class="rsc-header-actions"><a class="btn btn-secondary" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId) ?>">Back to site</a><?php if ($hostedUrl): ?><a class="btn btn-primary" target="_blank" rel="noopener" href="<?= e($hostedUrl) ?>">Open hosted site</a><?php endif; ?></div></div>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<section class="card rsc-section"><div class="card-header"><h2><?= $release ? 'Replace hosted release' : 'Upload hosted release' ?></h2></div><p>Upload a prepared <code>Slate React Release ZIP</code>. Slate validates the declared static files, stores them in its hardened uploads area, and serves the build through its public router. PHP and arbitrary server-side files are not accepted.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_action" value="upload_hosted_release"><?= csrf_field() ?><input type="hidden" name="site_id" value="<?= $siteId ?>"><div class="field"><label class="field-label" for="release_package">Release ZIP</label><input id="release_package" name="release_package" type="file" accept=".zip,application/zip" required><p class="field-help">Maximum upload: 90 MB compressed. Uploading a new release replaces the public files atomically after validation.</p></div><button class="btn btn-primary" type="submit"><?= $release ? 'Upload & replace release' : 'Upload hosted release' ?></button></form></section>
<?php if ($release && $hostedUrl): ?><section class="card rsc-section"><div class="card-header"><h2>Active Slate-hosted release</h2></div><dl class="rsc-release-meta"><div><dt>Public URL</dt><dd><a target="_blank" rel="noopener" href="<?= e($hostedUrl) ?>"><?= e($hostedUrl) ?></a></dd></div><div><dt>Files</dt><dd><?= (int)$release['file_count'] ?></dd></div><div><dt>Package size</dt><dd><?= e(number_format((int)$release['size_bytes'] / 1024 / 1024, 2)) ?> MB</dd></div><div><dt>Updated</dt><dd><?= e((string)$release['updated_at']) ?></dd></div></dl><form method="post" class="rsc-danger-zone" onsubmit="return confirm('Remove this hosted release? The site record, manifest, documents, and media mappings will remain.');"><?= csrf_field() ?><input type="hidden" name="_action" value="remove_hosted_release"><input type="hidden" name="site_id" value="<?= $siteId ?>"><button class="btn btn-sm btn-danger" type="submit">Remove hosted release</button></form></section><?php endif; ?>
<style>.rsc-section{margin-bottom:18px}.rsc-header-actions{display:flex;gap:10px;flex-wrap:wrap;margin:0;justify-content:flex-end}.rsc-release-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin:0}.rsc-release-meta div{padding:12px;border:1px solid var(--border,#e5e7eb);border-radius:6px}.rsc-release-meta dt{font-size:.78rem;color:var(--muted,#6b7280);margin-bottom:5px}.rsc-release-meta dd{margin:0;overflow-wrap:anywhere}.field-help{margin:5px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}.rsc-danger-zone{margin-top:18px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb)}@media(max-width:720px){.rsc-header-actions{justify-content:flex-start;margin-top:12px}.rsc-release-meta{grid-template-columns:1fr}}</style>
<?php require $root . '/admin/partials/footer.php'; ?>
