<?php
/** React Site Bridge — guided approved-photography upload and mapping workflow. */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
Auth::require();
Auth::requirePerm('react-site-bridge.manage');
ReactSiteBridgeAPI::ensureSchema();

$siteId = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$site = ReactSiteBridgeAPI::getSite($siteId);
if (!$site) { http_response_code(404); echo 'React site not found.'; exit; }
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'upload_map_photography') {
        try {
            if (empty($_POST['approval_confirmed'])) throw new InvalidArgumentException('Confirm that you are authorized to use this approved photography before uploading.');
            $logicalKey = (string)($_POST['logical_key'] ?? '');
            $result = ReactSiteBridgeAPI::uploadApprovedPhotographyAndMap(
                $siteId,
                'photography_file',
                $logicalKey,
                (string)($_POST['alt_text'] ?? ''),
                $_POST['focal_x'] ?? null,
                $_POST['focal_y'] ?? null
            );
            AuditLog::record('react_site_bridge.approved_photography_uploaded', 'site#' . $siteId . ' mapping#' . (int)$result['mapping_id'], ['media_id' => (int)($result['media']['id'] ?? 0), 'key' => $logicalKey]);
            $flash = ['type' => 'success', 'msg' => 'Photography uploaded to Slate Media and mapped as a draft. It is available in the visual editor image library; publish the site manifest when you are ready for frontend manifest consumers to receive the mapping.'];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$mappings = ReactSiteBridgeAPI::listMediaMappings($siteId);
$pageTitle = 'Approved photography · ' . $site['name'];
$currentNav = 'react-site-bridge';
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => 'React Sites', 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/index.php'], ['label' => $site['name'], 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId], ['label' => 'Approved photography']]); ?>
<div class="page-header"><div><h1>Approved photography</h1><p class="page-header-sub">Upload a licensed Kaimana image to Slate Media, then map it into this site’s visual-editor library.</p></div><div class="rsc-photo-actions"><a class="btn btn-secondary" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId . '#media') ?>">Media mappings</a><a class="btn btn-secondary" href="<?= e(SLATE_URL . '/admin/media.php') ?>">Slate Media</a><a class="btn btn-primary" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/visual.php?id=' . $siteId) ?>">Open visual editor</a></div></div>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<section class="card rsc-photo-intro"><div><span class="rsc-photo-step">1</span><h2>Upload</h2><p>JPEG, PNG, or WebP only, up to 15 MB. The image is registered through Slate Media under the active tenant.</p></div><div><span class="rsc-photo-step">2</span><h2>Map</h2><p>Choose a stable logical key and accessible alternative text. The mapping is saved as a draft; no public page changes yet.</p></div><div><span class="rsc-photo-step">3</span><h2>Use and publish</h2><p>Select the approved image from the visual editor library, then save and publish only after reviewing your revisions.</p></div></section>
<section class="card rsc-photo-card"><div class="card-header"><h2>Upload and map approved photography</h2></div><form method="post" enctype="multipart/form-data" class="rsc-photo-form"><input type="hidden" name="_action" value="upload_map_photography"><input type="hidden" name="site_id" value="<?= (int)$siteId ?>"><?= csrf_field() ?><div class="rsc-photo-grid"><label class="field"><span class="field-label">Photography file</span><input type="file" name="photography_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required><small>Do not upload customer-submitted, private, or unapproved files.</small></label><label class="field"><span class="field-label">Logical media key</span><input name="logical_key" required maxlength="120" pattern="[A-Za-z0-9._:-]+" placeholder="kaimana.electric.project.new"><small>Lowercase letters, numbers, dots, dashes, underscores, and colons are supported.</small></label><label class="field rsc-photo-wide"><span class="field-label">Alternative text</span><input name="alt_text" required maxlength="500" placeholder="Describe the important visual content and project context"><small>Used by assistive technology and carried into the visual editor library.</small></label><label class="field"><span class="field-label">Focal point X (optional)</span><input type="number" name="focal_x" min="0" max="1" step="0.01" placeholder="0.50"></label><label class="field"><span class="field-label">Focal point Y (optional)</span><input type="number" name="focal_y" min="0" max="1" step="0.01" placeholder="0.50"></label></div><label class="rsc-photo-confirm"><input type="checkbox" name="approval_confirmed" value="1" required> I confirm this photography is approved for this tenant and I am authorized to upload and use it on this website.</label><div class="rsc-photo-actions"><button class="btn btn-primary" type="submit">Upload and map as draft</button><a class="btn btn-secondary" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/visual.php?id=' . $siteId) ?>">Return to visual editor</a></div></form></section>
<section class="card rsc-photo-card"><div class="card-header"><h2>Mapped Slate Media</h2></div><?php if (!$mappings): ?><p class="rsc-empty">No mappings yet. Upload approved photography above or add an existing Slate Media image from Site management.</p><?php else: ?><div class="rsc-photo-list"><?php foreach ($mappings as $mapping): $asset = $mapping['media'] ?? null; if (!$asset) continue; $path = (string)($asset['path'] ?? ''); $src = $path !== '' ? rtrim(SLATE_URL, '/') . $path : ''; ?><article class="rsc-photo-item"><?php if ($src): ?><img src="<?= e($src) ?>" alt=""><!-- visual context is supplied in the adjacent text --><?php endif; ?><div><strong><?= e((string)$mapping['logical_key']) ?></strong><span><?= e((string)($asset['original_name'] ?? 'Slate Media image')) ?></span><p><?= e((string)($mapping['alt_text'] ?? 'No alternative text supplied.')) ?></p></div></article><?php endforeach; ?></div><?php endif; ?></section>
<style>.rsc-photo-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.rsc-photo-intro{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:18px;padding:22px}.rsc-photo-intro>div{position:relative;padding-left:38px}.rsc-photo-intro h2{font-size:1rem;margin:0 0 5px}.rsc-photo-intro p,.rsc-photo-form small,.rsc-photo-item span,.rsc-photo-item p,.rsc-empty{font-size:.86rem;color:var(--muted,#6b7280);line-height:1.45}.rsc-photo-step{position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:var(--primary,#2563eb);color:#fff;font-size:.78rem;font-weight:700}.rsc-photo-card{margin-bottom:18px}.rsc-photo-form{padding:0 20px 20px}.rsc-photo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.rsc-photo-wide{grid-column:1/-1}.rsc-photo-form .field{display:grid;gap:6px}.rsc-photo-form input[type=file]{padding:9px;border:1px dashed var(--border,#cbd5e1);border-radius:6px;background:var(--surface-alt,#f8fafc)}.rsc-photo-confirm{display:block;margin:18px 0;padding:12px;border-radius:6px;background:var(--surface-alt,#f8fafc);font-size:.9rem;line-height:1.45}.rsc-photo-confirm input{margin-right:7px}.rsc-photo-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;padding:0 20px 20px}.rsc-photo-item{display:flex;gap:12px;min-width:0;padding:10px;border:1px solid var(--border,#e5e7eb);border-radius:8px}.rsc-photo-item img{width:72px;height:56px;object-fit:cover;border-radius:5px;background:#e2e8f0}.rsc-photo-item strong,.rsc-photo-item span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rsc-photo-item p{margin:5px 0 0}.rsc-empty{padding:0 20px 20px}@media(max-width:720px){.rsc-photo-intro,.rsc-photo-grid{grid-template-columns:1fr}.rsc-photo-wide{grid-column:auto}.rsc-photo-actions{margin-top:12px}}</style>
<?php require $root . '/admin/partials/footer.php'; ?>
