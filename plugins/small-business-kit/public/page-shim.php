<?php
/**
 * Page shim — forwards /slate/<slug> to Content Builder's page renderer.
 *
 * PublicRouter has matched a single-segment slug (e.g. "/slate/home") as
 * a registered SBK route. We rewrite the request parameters CB expects
 * (?type=page&slug=<slug>) and require its renderer in-place.
 *
 * Authorization/maintenance gates are still enforced — the CB renderer
 * is the same one /p/<slug> uses, so behavior is identical.
 */

require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('small-business-kit');

$slug = (string)($_GET['_route_prefix'] ?? '');
if ($slug === '') {
    // Forward to CB's render with an empty slug — it will 404 through
    // the SBK-styled 404 template (matches the regular /p/<slug> 404).
    $slug = '__not_found__';
}

$_GET['type'] = 'page';
$_GET['slug'] = $slug;

require SLATE_ROOT . '/plugins/content-builder/public/render.php';
