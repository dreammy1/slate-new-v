<?php
/**
 * React Site Bridge — Slate-domain React preview wrapper.
 *
 * URL: /react-preview/<public-id>?path=/electric
 * Full-screen Slate-domain URL: /react-preview/<public-id>?path=/electric&fullscreen=1
 *
 * This page keeps preview addresses on Slate's domain while the configured
 * frontend base URL may be external or a direct Slate-hosted release path.
 */

if (!defined('SLATE_ROOT')) require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('react-site-bridge');
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
ReactSiteBridgeAPI::ensureSchema();

$route = trim((string)($_GET['_route_path'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);
$publicId = (string)($parts[0] ?? '');
if ($publicId === '' || count($parts) !== 1) {
    http_response_code(404);
    echo 'React preview not found.';
    return;
}

$result = ReactSiteBridgeAPI::activeManifestByPublicId($publicId);
if (!$result || !is_array($result['manifest'])) {
    http_response_code(404);
    echo 'Published React site not found.';
    return;
}

$site = $result['site'];
$frontendBase = rtrim((string)($site['preview_origin'] ?: $site['public_origin']), '/');
if ($frontendBase === '' || !filter_var($frontendBase, FILTER_VALIDATE_URL)) {
    http_response_code(409);
    echo 'No React preview origin is configured for this site.';
    return;
}

$requestedPath = (string)($_GET['path'] ?? '/');
if (!preg_match('#^/[A-Za-z0-9/_-]*$#', $requestedPath)) $requestedPath = '/';
$remoteUrl = $frontendBase . $requestedPath;
$isFullscreen = (string)($_GET['fullscreen'] ?? '') === '1';
$slatePreviewUrl = rtrim(SLATE_URL, '/') . '/react-preview/' . rawurlencode($publicId) . '?path=' . rawurlencode($requestedPath) . '&fullscreen=1';
$title = (string)($site['name'] ?? 'React site') . ($isFullscreen ? '' : ' preview');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?></title><style>html,body{height:100%;margin:0;background:#f4f2ee;color:#102832;font-family:system-ui,-apple-system,sans-serif}.preview-bar{height:48px;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 16px;background:#0a1e3d;color:#fff;font-size:14px}.preview-bar strong{font-weight:650}.preview-bar a{color:#d9f6ff;text-decoration:none}.preview-frame{display:block;width:100%;height:<?= $isFullscreen ? '100vh' : 'calc(100vh - 48px)' ?>;border:0;background:#fff}</style></head><body><?php if (!$isFullscreen): ?><div class="preview-bar"><strong><?= e((string)$site['name']) ?> · Preview</strong><a href="<?= e($slatePreviewUrl) ?>" target="_blank" rel="noopener">Open full-screen ↗</a></div><?php endif; ?><iframe class="preview-frame" title="<?= e($title) ?>" src="<?= e($remoteUrl) ?>" referrerpolicy="strict-origin-when-cross-origin"></iframe></body></html>
