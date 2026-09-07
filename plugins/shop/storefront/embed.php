<?php
/**
 * Shop storefront — external embeddable product grid.
 *
 * URL: /shop/embed?cat=<category-slug>&limit=<n>&cols=<2|3|4>
 *
 * A standalone, frame-anywhere page that renders the product grid for use
 * inside an <iframe> on ANY external website. Cards open the real shop in a
 * new tab so product pages / cart work in a same-site context.
 */

// Resolve Slate root (mirrors index.php).
$root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
if (!file_exists($root . '/config.php')) {
    $dir = __DIR__;
    while ($dir !== '/' && !file_exists($dir . '/config.php')) {
        $dir = dirname($dir);
    }
    $root = $dir;
}
require $root . '/config.php';

if (!class_exists('ShopAPI') || !PluginLoader::isActive('shop')) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><p>Shop unavailable.</p>';
    return;
}

// Allow this page to be framed by any external site.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors *");
    // Embeds are dynamic; don't let a CDN pin stale markup.
    header('Cache-Control: public, max-age=120');
}

$source = (string)($_GET['cat'] ?? '');
$limit  = (int)($_GET['limit'] ?? 12);
$cols   = (int)($_GET['cols'] ?? 3);

echo ShopAPI::renderEmbedPage($source, $limit, $cols);
